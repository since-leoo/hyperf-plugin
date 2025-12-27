<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;

/**
 * 插件发现器 - 实现插件发现和信息获取
 * 
 * 负责发现项目中的可用插件，获取已安装插件列表，
 * 检查插件状态，以及获取插件的配置信息。
 */
class PluginDiscoverer implements PluginDiscovererInterface
{
    /**
     * 插件配置读取器
     */
    private PluginConfigReaderInterface $configReader;

    /**
     * 配置写入器
     */
    private ConfigWriterInterface $configWriter;

    /**
     * 项目根路径
     */
    private string $basePath;

    /**
     * 插件目录名称
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

    /**
     * 获取默认项目根路径
     */
    private function getDefaultBasePath(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH;
        }
        return getcwd();
    }


    /**
     * {@inheritdoc}
     */
    public function discoverLocalPlugins(): array
    {
        $pluginsPath = $this->basePath . '/' . $this->pluginsDir;
        $plugins = [];

        if (!is_dir($pluginsPath)) {
            return $plugins;
        }

        foreach (scandir($pluginsPath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $pluginPath = $pluginsPath . '/' . $item;
            if (!is_dir($pluginPath)) {
                continue;
            }

            // 优先从 plugin.json 读取信息
            $pluginConfig = $this->configReader->read($pluginPath);
            
            if (!empty($pluginConfig)) {
                $packageName = $pluginConfig['name'] ?? '';
                $plugins[] = [
                    'name' => $packageName,
                    'path' => $pluginPath,
                    'version' => $pluginConfig['version'] ?? '1.0.0',
                    'description' => $this->configReader->get($pluginConfig, 'description'),
                    'author' => $this->configReader->get($pluginConfig, 'author'),
                    'priority' => $this->configReader->get($pluginConfig, 'priority'),
                    'dependencies' => $this->configReader->get($pluginConfig, 'dependencies'),
                    'installed' => $this->isInstalled($packageName),
                    'enabled' => $this->isEnabled($packageName),
                ];
                continue;
            }

            // 回退到 composer.json
            $composerPath = $pluginPath . '/composer.json';
            if (file_exists($composerPath)) {
                $composer = json_decode(file_get_contents($composerPath), true);
                if (is_array($composer) && isset($composer['name'])) {
                    $packageName = $composer['name'];
                    $plugins[] = [
                        'name' => $packageName,
                        'path' => $pluginPath,
                        'version' => $composer['version'] ?? '1.0.0',
                        'description' => $composer['description'] ?? '',
                        'author' => '',
                        'priority' => 0,
                        'dependencies' => [],
                        'installed' => $this->isInstalled($packageName),
                        'enabled' => $this->isEnabled($packageName),
                    ];
                }
            }
        }

        return $plugins;
    }

    /**
     * {@inheritdoc}
     */
    public function getInstalledPlugins(): array
    {
        $config = $this->configWriter->getConfig();
        return $config['installed'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function isInstalled(string $packageName): bool
    {
        $installed = $this->getInstalledPlugins();
        return isset($installed[$packageName]);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(string $packageName): bool
    {
        $config = $this->configWriter->getConfig();
        $enabled = $config['enabled'] ?? [];
        return $enabled[$packageName] ?? false;
    }


    /**
     * {@inheritdoc}
     */
    public function getPluginConfigProvider(string $packageName): ?string
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return null;
        }

        // 优先从 plugin.json 读取 configProvider
        $pluginConfig = $this->configReader->read($pluginPath);
        if (!empty($pluginConfig['configProvider'])) {
            $configProvider = $pluginConfig['configProvider'];
            if (is_string($configProvider) && class_exists($configProvider)) {
                return $configProvider;
            }
        }

        // 回退到 composer.json 的 extra.hyperf.config
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            return null;
        }

        $configProvider = $composer['extra']['hyperf']['config'] ?? null;
        
        if (is_string($configProvider) && class_exists($configProvider)) {
            return $configProvider;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPluginClass(string $packageName): ?string
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return null;
        }

        // 从 composer.json 读取 autoload 信息来推断 Plugin 类
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            return null;
        }

        // 获取 PSR-4 命名空间
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        foreach ($psr4 as $namespace => $path) {
            $pluginClass = rtrim($namespace, '\\') . '\\Plugin';
            if (class_exists($pluginClass)) {
                return $pluginClass;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPluginJsonConfig(string $packageName): array
    {
        $pluginPath = $this->getPluginPath($packageName);
        if ($pluginPath === null) {
            return [];
        }

        return $this->configReader->read($pluginPath);
    }

    /**
     * 获取插件路径
     * 
     * @param string $packageName 插件包名
     * @return string|null 插件路径，不存在则返回 null
     */
    public function getPluginPath(string $packageName): ?string
    {
        // 首先检查已安装插件配置
        $installed = $this->getInstalledPlugins();
        if (isset($installed[$packageName]['path'])) {
            $path = $installed[$packageName]['path'];
            if (is_dir($path)) {
                return $path;
            }
        }

        // 检查本地插件目录
        $localPlugins = $this->discoverLocalPlugins();
        foreach ($localPlugins as $plugin) {
            if ($plugin['name'] === $packageName) {
                return $plugin['path'];
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
     * 获取项目根路径
     * 
     * @return string 项目根路径
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * 获取插件目录名称
     * 
     * @return string 插件目录名称
     */
    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }
}
