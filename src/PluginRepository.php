<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\PluginInterface;
use SinceLeoo\Plugin\Contract\PluginRepositoryInterface;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;

/**
 * 插件仓库 - 实现插件实例的存储和检索
 * 
 * 负责管理插件实例的注册、获取、检索等操作，
 * 支持按优先级排序获取插件列表。
 */
class PluginRepository implements PluginRepositoryInterface
{
    /**
     * 已注册的插件实例
     * 
     * @var array<string, PluginInterface>
     */
    private array $plugins = [];

    /**
     * 配置写入器（用于检查插件启用状态）
     */
    private ConfigWriterInterface $configWriter;

    public function __construct(ConfigWriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    /**
     * {@inheritdoc}
     */
    public function register(PluginInterface $plugin): void
    {
        $name = $plugin->getName();
        $this->plugins[$name] = $plugin;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        return array_values($this->plugins);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnabled(): array
    {
        $config = $this->configWriter->getConfig();
        $enabledConfig = $config['enabled'] ?? [];

        return array_values(array_filter(
            $this->plugins,
            fn(PluginInterface $plugin) => $enabledConfig[$plugin->getName()] ?? false
        ));
    }

    /**
     * {@inheritdoc}
     * 
     * 按优先级降序排列（数值越大越先加载）
     * 相同优先级的插件按名称字母顺序排列
     */
    public function getByPriority(): array
    {
        $plugins = $this->plugins;

        uasort($plugins, function (PluginInterface $a, PluginInterface $b): int {
            $priorityA = $a->getPriority();
            $priorityB = $b->getPriority();

            // 优先级高的排在前面（降序）
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            // 相同优先级按名称字母顺序排列
            return strcmp($a->getName(), $b->getName());
        });

        return array_values($plugins);
    }

    /**
     * 清空所有已注册的插件
     * 
     * 主要用于测试目的
     */
    public function clear(): void
    {
        $this->plugins = [];
    }

    /**
     * 获取已注册插件的数量
     * 
     * @return int 插件数量
     */
    public function count(): int
    {
        return count($this->plugins);
    }
}
