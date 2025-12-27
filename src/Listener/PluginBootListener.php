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

namespace SinceLeoo\Plugin\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;

/**
 * 插件启动监听器.
 *
 * 在应用启动时自动加载所有已启用的插件。
 * 使用依赖注入获取 PluginManager 实例。
 *
 * @see Requirements 3.3, 3.4
 */
class PluginBootListener implements ListenerInterface
{
    public function __construct(
        private PluginManagerInterface $pluginManager
    ) {
    }

    /**
     * 监听的事件列表.
     *
     * @return array<class-string>
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * 处理事件.
     *
     * 在应用启动时调用 PluginManager 的 bootPlugins 方法，
     * 按优先级顺序加载所有已启用的插件。
     *
     * @param object $event 事件对象
     */
    public function process(object $event): void
    {
        $this->pluginManager->bootPlugins();
    }
}
