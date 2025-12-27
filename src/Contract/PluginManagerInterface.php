<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件管理器接口
 * 
 * 定义插件管理的核心操作，包括安装、卸载、启用、禁用等。
 * 
 * @see Requirements 3.3, 3.4
 */
interface PluginManagerInterface
{
    /**
     * 安装插件
     * 
     * @param string $packageName 插件包名
     * @param array $options 安装选项
     * @return bool 安装是否成功
     */
    public function install(string $packageName, array $options = []): bool;

    /**
     * 卸载插件
     * 
     * @param string $packageName 插件包名
     * @param bool $force 是否强制卸载（忽略依赖检查）
     * @param bool|null $rollback 是否回滚迁移，null 表示使用 plugin.json 配置
     * @return bool 卸载是否成功
     */
    public function uninstall(string $packageName, bool $force = false, ?bool $rollback = null): bool;

    /**
     * 启用插件
     * 
     * @param string $packageName 插件包名
     * @return bool 启用是否成功
     */
    public function enable(string $packageName): bool;

    /**
     * 禁用插件
     * 
     * @param string $packageName 插件包名
     * @return bool 禁用是否成功
     */
    public function disable(string $packageName): bool;

    /**
     * 加载所有已启用的插件
     * 
     * 按优先级顺序加载插件，单个插件失败不影响其他插件。
     */
    public function bootPlugins(): void;

    /**
     * 获取已加载的插件
     * 
     * @return array 已加载的插件列表
     */
    public function getLoadedPlugins(): array;

    /**
     * 检查插件依赖
     * 
     * @param string $packageName 插件包名
     * @return array 缺失的依赖列表，空数组表示依赖满足
     */
    public function checkDependencies(string $packageName): array;
}
