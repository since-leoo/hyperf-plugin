<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use Hyperf\Support\Composer;

/**
 * 插件引导器 - 在 Hyperf ClassLoader::init() 之前调用.
 *
 * 负责：
 * 1. 注册插件的 PSR-4 自动加载（让 Hyperf 注解扫描能发现插件类）
 * 2. 将插件的 ConfigProvider 注入到 Composer extra（让 ProviderConfig::load() 能发现）
 * 3. 加载插件的 helper 文件
 *
 * 用法：在 bin/hyperf.php 中，require autoload.php 之后、ClassLoader::init() 之前调用：
 *
 *   require BASE_PATH . '/vendor/autoload.php';
 *   \SinceLeoo\Plugin\PluginBootstrap::init();
 *   \Hyperf\Di\ClassLoader::init();
 */
class PluginBootstrap
{
    public static function init(?string $basePath = null, ?string $pluginsDir = null): void
    {
        $basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : getcwd());
        $pluginsDir = $pluginsDir ?? self::resolvePluginsDir($basePath);
        $pluginsPath = $basePath . '/' . $pluginsDir;

        if (! is_dir($pluginsPath)) {
            return;
        }

        $loader = require $basePath . '/vendor/autoload.php';

        // 触发 Composer 加载 lock 内容，填充 $extra 静态属性
        Composer::getLockContent();

        $ref = new \ReflectionClass(Composer::class);
        $extraProp = $ref->getProperty('extra');
        $extra = $extraProp->getValue();

        foreach (scandir($pluginsPath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $pluginPath = $pluginsPath . '/' . $item;
            $jsonFile = $pluginPath . '/plugin.json';
            if (! is_file($jsonFile)) {
                continue;
            }

            $config = json_decode(file_get_contents($jsonFile), true);
            if (empty($config['namespace']) || empty($config['enabled']) || ! file_exists($pluginPath . '/install.lock')) {
                continue;
            }

            // 1. 注册 PSR-4 自动加载
            $namespace = rtrim($config['namespace'], '\\') . '\\';
            $srcPath = $pluginPath . '/src/';
            if (is_dir($srcPath)) {
                $loader->addPsr4($namespace, $srcPath);
            }

            // 2. 注入 ConfigProvider 到 Composer extra
            if (! empty($config['configProvider'])) {
                $pluginName = $config['name'] ?? ('plugin/' . $item);
                $extra[$pluginName] = [
                    'hyperf' => [
                        'config' => $config['configProvider'],
                    ],
                ];
            }

            // 3. 加载 helper 文件
            $helperFile = $pluginPath . '/src/Helper/helper.php';
            if (is_file($helperFile)) {
                require_once $helperFile;
            }
        }

        $extraProp->setValue(null, $extra);
    }

    /**
     * 从 config/autoload/plugins.php 读取 plugins_path，默认 'plugins'.
     */
    private static function resolvePluginsDir(string $basePath): string
    {
        $configFile = $basePath . '/config/autoload/plugins.php';
        if (is_file($configFile)) {
            $config = require $configFile;
            if (is_array($config) && ! empty($config['plugins_path'])) {
                return $config['plugins_path'];
            }
        }

        return 'plugins';
    }
}
