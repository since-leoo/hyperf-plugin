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
 * 插件迁移回滚完成事件.
 *
 * 在插件数据库迁移回滚完成后触发。
 *
 * @see Requirements 10.2 (卸载时回滚迁移)
 */
class PluginMigrationRolledBackEvent extends PluginEvent
{
    /**
     * @param string $packageName 插件包名
     * @param array $pluginInfo 插件信息
     * @param array $rolledBackMigrations 已回滚的迁移文件列表
     */
    public function __construct(
        string $packageName,
        array $pluginInfo,
        public readonly array $rolledBackMigrations
    ) {
        parent::__construct($packageName, $pluginInfo);
    }
}
