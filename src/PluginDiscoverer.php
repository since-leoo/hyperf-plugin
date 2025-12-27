<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\ConfigWriterInterface;
use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;

/**
 * 插件发现器 - 实现插件发现和信息获取.
 *
 * 负责发现项目中的可用插件，获取已安装插件列表，
 * 检查插件状态，以及获取插件的配置信息。
 */
class PluginDiscoverer implements PluginDiscovererInterface
{
    /**
     * 插件配置读取器.
     */
    private PluginConfigReaderInterface $configReader;

    /**
     * 配置写入器.
     */
    private ConfigWriterInterface $configWriter;

    /**
     * 项目根路径.
     */
    private string $basePath;

    /**
     * 插件目录名称.
     */
    private string $pluginsDir;

    public function __construct(
        PluginConfigReaderInterface $configReader,
        ConfigWriterInterface $configWriter,
        ?string $basePath = null,
        ?string $pluginsDir = null
    ) {
        $this->configReader = $configReader;
        $this->configWriter = $configWriter;
        $this->basePath = $basePath ?? $this->getDefaultBasePath();
        $this->pluginsDir = $pluginsDir ?? 'plugins';
    }

    public function discoverLocalPlugins(): array
    {
        $pluginsPath = $this->basePath . '/' . $this->pluginsDir;
        $plugins = [];

        if (! is_dir($pluginsPath)) {
            return $plugins;
        }

        foreach (scandir($pluginsPath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $pluginPath = $pluginsPath . '/' . $item;
            if (! is_dir($pluginPath)) {
                continue;
            }

            // 优先从 plugin.json 读取信息
            $pluginConfig = $this->configReader->read($pluginPath);

            if (! empty($pluginConfig)) {
                $packageName = $pluginConfig['name'] ?? '';
                // 直接检查 install.lock 文件，避免递归调用
                $isInstalled = file_exists($pluginPath . '/install.lock');
                $plugins[] = [
                    'name' => $packageName,
                    'path' => $pluginPath,
                    'version' => $pluginConfig['version'] ?? '1.0.0',
                    'description' => $this->configReader->get($pluginConfig, 'description'),
                    'author' => $this->configReader->get($pluginConfig, 'author'),
                    'priority' => $this->configReader->get($pluginConfig, 'priority'),
                    'dependencies' => $this->configReader->get($pluginConfig, 'dependencies'),
                    'installed' => $isInstalled,
                    'enabled' => $this->isEnabled($packageName),
                ];
                continue;
            }
        }

        return $plugins;
    }

    public function getInstalledPlugins(): array
    {
        $installed = [];
        $localPlugins = $this->discoverLocalPlugins();

        foreach ($localPlugins as $plugin) {
            $lockFile = $plugin['path'] . '/install.lock';
            if (file_exists($lockFile)) {
                $lockContent = json_decode(file_get_contents($lockFile), true) ?? [];
                $installed[$plugin['name']] = array_merge($plugin, [
                    'installed_at' => $lockContent['installed_at'] ?? null,
                    'migrations_executed' => $lockContent['migrations_executed'] ?? [],
                    'seeder_executed' => $lockContent['seeder_executed'] ?? false,
                ]);
            }
        }

        return $installed;
    }

    public function isInstalled(string $packageName): bool
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return false;
        }

        return file_exists($pluginPath . '/install.lock');
    }

    public function isEnabled(string $packageName): bool
    {
        // 直接从 plugin.json 读取 enabled 状态
        $pluginConfig = $this->getPluginJsonConfig($packageName);
        return $pluginConfig['enabled'] ?? false;
    }

    public function getPluginConfigProvider(string $packageName): ?string
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return null;
        }

        // 优先从 plugin.json 读取 configProvider
        $pluginConfig = $this->configReader->read($pluginPath);
        if (! empty($pluginConfig['configProvider'])) {
            $configProvider = $pluginConfig['configProvider'];

            // 先注册自动加载
            $this->registerPluginAutoload($pluginPath, $pluginConfig);

            if (is_string($configProvider) && class_exists($configProvider)) {
                return $configProvider;
            }
        }

        return null;
    }

    public function getPluginClass(string $packageName): ?string
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return null;
        }

        // 优先从 plugin.json 读取 namespace
        $pluginConfig = $this->configReader->read($pluginPath);
        if (! empty($pluginConfig['namespace'])) {
            // 先注册自动加载
            $this->registerPluginAutoload($pluginPath, $pluginConfig);

            $pluginClass = rtrim($pluginConfig['namespace'], '\\') . '\\Plugin';
            if (class_exists($pluginClass)) {
                return $pluginClass;
            }
        }

        return null;
    }

    /**
     * 注册插件的 PSR-4 自动加载.
     *
     * @param string $pluginPath 插件路径
     * @param array $pluginConfig 插件配置
     */
    public function registerPluginAutoload(string $pluginPath, array $pluginConfig): void
    {
        if (empty($pluginConfig['namespace'])) {
            return;
        }

        $namespace = rtrim($pluginConfig['namespace'], '\\') . '\\';
        $srcPath = $pluginPath . '/src/';

        if (! is_dir($srcPath)) {
            return;
        }

        // 使用 Composer 的 ClassLoader 注册 PSR-4 自动加载
        $composerAutoload = $this->basePath . '/vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            $loader = require $composerAutoload;
            if ($loader instanceof \Composer\Autoload\ClassLoader) {
                // 检查是否已注册
                $prefixes = $loader->getPrefixesPsr4();
                if (! isset($prefixes[$namespace])) {
                    $loader->addPsr4($namespace, $srcPath);
                }
            }
        }
    }

    public function getPluginJsonConfig(string $packageName): array
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return [];
        }

        return $this->configReader->read($pluginPath);
    }

    /**
     * 获取插件路径.
     *
     * @param string $packageName 插件包名
     * @return null|string 插件路径，不存在则返回 null
     */
    public function getPluginPath(string $packageName): ?string
    {
        // 检查本地插件目录（直接扫描，避免递归）
        $pluginsPath = $this->basePath . '/' . $this->pluginsDir;
        if (is_dir($pluginsPath)) {
            foreach (scandir($pluginsPath) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $pluginPath = $pluginsPath . '/' . $item;
                if (! is_dir($pluginPath)) {
                    continue;
                }

                $pluginConfig = $this->configReader->read($pluginPath);
                if (! empty($pluginConfig) && ($pluginConfig['name'] ?? '') === $packageName) {
                    return $pluginPath;
                }
            }
        }

        // 检查 vendor 目录
        $vendorPath = $this->basePath . '/vendor/' . $packageName;
        if (is_dir($vendorPath)) {
            return $vendorPath;
        }

        return null;
    }

    /**
     * 获取项目根路径.
     *
     * @return string 项目根路径
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * 获取插件目录名称.
     *
     * @return string 插件目录名称
     */
    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }

    /**
     * 获取默认项目根路径.
     */
    private function getDefaultBasePath(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH;
        }
        return getcwd();
    }
}
