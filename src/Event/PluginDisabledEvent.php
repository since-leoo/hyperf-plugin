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
 * 插件禁用事件.
 *
 * 在插件被禁用后触发。
 *
 * @see Requirements 10.4
 */
class PluginDisabledEvent extends PluginEvent
{
}
