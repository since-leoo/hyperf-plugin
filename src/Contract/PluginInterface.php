<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件接口 - 所有插件必须实现的标准接口
 *
 * 定义了插件的生命周期方法。
 * 插件元数据（名称、版本、描述等）通过 plugin.json 配置文件定义。
 */
interface PluginInterface
{
    /**
     * 插件安装时调用（在迁移和填充之后）
     */
    public function install(): void;

    /**
     * 插件卸载时调用（在回滚迁移之前）
     */
    public function uninstall(): void;

    /**
     * 插件启动时调用（每次应用启动时，仅 enabled: true 的插件）
     */
    public function boot(): void;
}
