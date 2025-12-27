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
 * 插件卸载完成事件.
 *
 * 在插件成功卸载后触发。
 *
 * @see Requirements 10.2
 */
class PluginUninstalledEvent extends PluginEvent
{
}
