<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件仓库接口 - 定义插件实例的存储和检索操作
 * 
 * 负责管理插件实例的注册、获取、检索等操作，
 * 支持按优先级排序获取插件列表。
 */
interface PluginRepositoryInterface
{
    /**
     * 注册插件实例
     * 
     * @param PluginInterface $plugin 插件实例
     */
    public function register(PluginInterface $plugin): void;

    /**
     * 获取指定名称的插件实例
     * 
     * @param string $name 插件名称
     * @return PluginInterface|null 插件实例，不存在则返回 null
     */
    public function get(string $name): ?PluginInterface;

    /**
     * 检查插件是否已注册
     * 
     * @param string $name 插件名称
     * @return bool 是否已注册
     */
    public function has(string $name): bool;

    /**
     * 获取所有已注册的插件
     * 
     * @return PluginInterface[] 所有插件实例数组
     */
    public function all(): array;

    /**
     * 获取所有已启用的插件
     * 
     * @return PluginInterface[] 已启用的插件实例数组
     */
    public function getEnabled(): array;

    /**
     * 按优先级排序获取插件列表
     * 
     * 优先级高的插件排在前面（降序排列）
     * 相同优先级的插件按名称字母顺序排列
     * 
     * @return PluginInterface[] 按优先级排序的插件实例数组
     */
    public function getByPriority(): array;
}
