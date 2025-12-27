<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件接口 - 所有插件必须实现的标准接口
 * 
 * 定义了插件的元数据获取方法和生命周期方法。
 */
interface PluginInterface
{
    /**
     * 获取插件名称 (composer package name)
     */
    public function getName(): string;

    /**
     * 获取插件版本
     */
    public function getVersion(): string;

    /**
     * 获取插件描述
     */
    public function getDescription(): string;

    /**
     * 获取插件作者
     */
    public function getAuthor(): string;

    /**
     * 获取插件依赖的其他插件
     * 
     * @return string[] 依赖的插件包名数组
     */
    public function getDependencies(): array;

    /**
     * 获取插件加载优先级 (数值越大越先加载)
     */
    public function getPriority(): int;

    /**
     * 插件安装时调用
     */
    public function install(): void;

    /**
     * 插件卸载时调用
     */
    public function uninstall(): void;

    /**
     * 插件启用时调用
     */
    public function enable(): void;

    /**
     * 插件禁用时调用
     */
    public function disable(): void;

    /**
     * 插件启动时调用 (每次应用启动)
     */
    public function boot(): void;
}
