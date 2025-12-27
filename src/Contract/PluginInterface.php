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
 * 插件接口 - 所有插件必须实现的标准接口.
 *
 * 定义了插件的生命周期方法。
 * 插件元数据（名称、版本、描述等）通过 plugin.json 配置文件定义。
 */
interface PluginInterface
{
    /**
     * 插件安装时调用（在迁移和填充之后）.
     */
    public function install(): void;

    /**
     * 插件卸载时调用（在回滚迁移之前）.
     */
    public function uninstall(): void;

    /**
     * 插件启用时调用.
     */
    public function enable(): void;

    /**
     * 插件禁用时调用.
     */
    public function disable(): void;

    /**
     * 插件启动时调用（每次应用启动时）.
     */
    public function boot(): void;
}
