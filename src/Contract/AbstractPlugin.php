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

namespace SinceLeoo\Plugin\Contract;

/**
 * 抽象插件基类 - 提供插件接口的默认实现.
 *
 * 插件开发者继承此类，所有生命周期方法都有空的默认实现，
 * 只需按需覆盖需要的方法即可。
 *
 * 插件元数据（名称、版本、描述等）通过 plugin.json 配置文件定义，
 * 无需在代码中实现。
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * 插件安装时调用（在迁移和填充之后）.
     *
     * 默认空实现，子类可按需覆盖
     */
    public function install(): void
    {
        // 默认空实现
    }

    /**
     * 插件卸载时调用（在回滚迁移之前）.
     *
     * 默认空实现，子类可按需覆盖
     */
    public function uninstall(): void
    {
        // 默认空实现
    }

    /**
     * 插件启用时调用.
     *
     * 默认空实现，子类可按需覆盖
     */
    public function enable(): void
    {
        // 默认空实现
    }

    /**
     * 插件禁用时调用.
     *
     * 默认空实现，子类可按需覆盖
     */
    public function disable(): void
    {
        // 默认空实现
    }

    /**
     * 插件启动时调用（每次应用启动时）.
     *
     * 默认空实现，子类可按需覆盖
     */
    public function boot(): void
    {
        // 默认空实现
    }
}
