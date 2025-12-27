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
use SinceLeoo\Plugin\Contract\PluginInterface;
use SinceLeoo\Plugin\Contract\PluginRepositoryInterface;

/**
 * 插件仓库 - 实现插件实例的存储和检索.
 *
 * 负责管理插件实例的注册、获取、检索等操作。
 * 插件元数据（名称、优先级等）通过 plugin.json 管理，
 * 仓库只负责存储运行时的插件实例。
 */
class PluginRepository implements PluginRepositoryInterface
{
    /**
     * 已注册的插件实例.
     *
     * @var array<string, PluginInterface>
     */
    private array $plugins = [];

    /**
     * 配置写入器（用于检查插件启用状态）.
     */
    private ConfigWriterInterface $configWriter;

    public function __construct(ConfigWriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    public function register(string $packageName, PluginInterface $plugin): void
    {
        $this->plugins[$packageName] = $plugin;
    }

    public function get(string $packageName): ?PluginInterface
    {
        return $this->plugins[$packageName] ?? null;
    }

    public function has(string $packageName): bool
    {
        return isset($this->plugins[$packageName]);
    }

    public function all(): array
    {
        return $this->plugins;
    }

    public function getEnabled(): array
    {
        $config = $this->configWriter->getConfig();
        $enabledConfig = $config['enabled'] ?? [];

        return array_filter(
            $this->plugins,
            fn (PluginInterface $plugin, string $packageName) => $enabledConfig[$packageName] ?? false,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * 清空所有已注册的插件.
     *
     * 主要用于测试目的
     */
    public function clear(): void
    {
        $this->plugins = [];
    }

    /**
     * 获取已注册插件的数量.
     *
     * @return int 插件数量
     */
    public function count(): int
    {
        return count($this->plugins);
    }
}
