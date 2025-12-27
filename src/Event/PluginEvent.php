<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Event;

/**
 * 插件事件基类
 * 
 * 所有插件生命周期事件的基类，包含插件包名和插件信息。
 */
class PluginEvent
{
    public function __construct(
        public readonly string $packageName,
        public readonly array $pluginInfo
    ) {}
}
