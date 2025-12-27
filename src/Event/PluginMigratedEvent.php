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

namespace SinceLeoo\Plugin\Event;

/**
 * 插件迁移执行完成事件.
 *
 * 在插件数据库迁移执行完成后触发。
 *
 * @see Requirements 10.6
 */
class PluginMigratedEvent extends PluginEvent
{
    /**
     * @param string $packageName 插件包名
     * @param array $pluginInfo 插件信息
     * @param array $executedMigrations 已执行的迁移文件列表
     */
    public function __construct(
        string $packageName,
        array $pluginInfo,
        public readonly array $executedMigrations
    ) {
        parent::__construct($packageName, $pluginInfo);
    }
}
