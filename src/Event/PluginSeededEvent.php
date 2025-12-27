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
 * 插件数据填充完成事件.
 *
 * 在插件填充器执行完成后触发。
 *
 * @see Requirements 10.7
 */
class PluginSeededEvent extends PluginEvent
{
    /**
     * @param string $packageName 插件包名
     * @param array $pluginInfo 插件信息
     * @param string $seederClass 执行的填充器类名
     */
    public function __construct(
        string $packageName,
        array $pluginInfo,
        public readonly string $seederClass
    ) {
        parent::__construct($packageName, $pluginInfo);
    }
}
