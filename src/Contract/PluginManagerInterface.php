<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件管理器接口
 *
 * 定义插件管理的核心操作，包括安装、卸载等。
 */
interface PluginManagerInterface
{
    /**
     * 安装插件
     *
     * @param string $packageName 插件包名
     * @param array $options 安装选项
     *                       - auto_install_deps: bool 是否自动安装依赖（默认 true）
     *                       - skip_migrations: bool 是否跳过迁移（默认 false）
     *                       - skip_seeders: bool 是否跳过填充器（默认 false）
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
     * @return array{missing: array, disabled: array} 依赖检查结果
     *               - missing: 未安装的依赖列表
     *               - disabled: 已安装但未启用的依赖列表
     */
    public function checkDependencies(string $packageName): array;
}
