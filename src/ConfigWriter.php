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

/**
 * 配置写入器 - 读取全局插件配置.
 *
 * 负责读取插件配置文件（如插件目录路径等）。
 * 插件的启用状态由各插件的 plugin.json 管理。
 * 安装状态由 install.lock 文件管理。
 */
class ConfigWriter implements ConfigWriterInterface
{
    private string $configPath;

    private array $defaultConfig = [
        'plugins_path' => 'plugins',
    ];

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
    }

    public function getConfig(): array
    {
        if (! file_exists($this->configPath)) {
            return $this->defaultConfig;
        }

        $config = include $this->configPath;

        if (! is_array($config)) {
            return $this->defaultConfig;
        }

        return array_merge($this->defaultConfig, $config);
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    private function getDefaultConfigPath(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH . '/config/autoload/plugins.php';
        }
        return getcwd() . '/config/autoload/plugins.php';
    }
}
