<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 抽象插件基类 - 提供插件接口的默认实现
 * 
 * 插件开发者可以继承此类，只需实现必要的元数据方法，
 * 生命周期方法已提供空的默认实现。
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * 获取插件依赖的其他插件
     * 
     * @return string[] 默认返回空数组，表示无依赖
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * 获取插件加载优先级
     * 
     * @return int 默认返回 0
     */
    public function getPriority(): int
    {
        return 0;
    }

    /**
     * 插件安装时调用
     * 
     * 默认空实现，子类可按需覆盖
     */
    public function install(): void
    {
        // 默认空实现
    }

    /**
     * 插件卸载时调用
     * 
     * 默认空实现，子类可按需覆盖
     */
    public function uninstall(): void
    {
        // 默认空实现
    }

    /**
     * 插件启用时调用
     * 
     * 默认空实现，子类可按需覆盖
     */
    public function enable(): void
    {
        // 默认空实现
    }

    /**
     * 插件禁用时调用
     * 
     * 默认空实现，子类可按需覆盖
     */
    public function disable(): void
    {
        // 默认空实现
    }

    /**
     * 插件启动时调用 (每次应用启动)
     * 
     * 默认空实现，子类可按需覆盖
     */
    public function boot(): void
    {
        // 默认空实现
    }
}
