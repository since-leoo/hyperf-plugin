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
 * 插件启动完成事件.
 *
 * 在插件 boot 方法被调用后触发。
 *
 * @see Requirements 10.5
 */
class PluginBootedEvent extends PluginEvent
{
}
