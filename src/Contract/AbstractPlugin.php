<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 抽象插件基类 - 提供插件接口的默认实现
 *
 * 插件开发者继承此类，所有生命周期方法都有空的默认实现，
 * 只需按需覆盖需要的方法即可。
 *
 * 插件元数据（名称、版本、描述等）通过 plugin.json 配置文件定义。
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * 插件安装时调用（在迁移和填充之后）
     */
    public function install(): void
    {
        // 默认空实现
    }

    /**
     * 插件卸载时调用（在回滚迁移之前）
     */
    public function uninstall(): void
    {
        // 默认空实现
    }

    /**
     * 插件启动时调用（每次应用启动时，仅 enabled: true 的插件）
     */
    public function boot(): void
    {
        // 默认空实现
    }
}
