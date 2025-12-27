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

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Command\PluginInstallCommand;
use SinceLeoo\Plugin\Command\PluginListCommand;
use SinceLeoo\Plugin\Command\PluginMakeCommand;
use SinceLeoo\Plugin\Command\PluginSeedCommand;
use SinceLeoo\Plugin\Command\PluginUnInstallCommand;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;
use SinceLeoo\Plugin\Contract\MigrationRunnerInterface;
use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use SinceLeoo\Plugin\Contract\PluginRepositoryInterface;
use SinceLeoo\Plugin\Contract\SeederRunnerInterface;
use SinceLeoo\Plugin\Listener\PluginBootListener;

/**
 * Hyperf 插件管理包配置提供者.
 *
 * 注册所有命令、监听器、依赖注入绑定和发布配置。
 *
 * @see Requirements 11.1, 11.2
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            // 注册所有插件管理命令
            'commands' => [
                PluginInstallCommand::class,
                PluginUnInstallCommand::class,
                PluginListCommand::class,
                PluginSeedCommand::class,
                PluginMakeCommand::class,
            ],
            // 注册监听器
            'listeners' => [
                PluginBootListener::class,
            ],
            // 依赖注入绑定
            'dependencies' => [
                // 接口到实现类的绑定
                PluginManagerInterface::class => PluginManager::class,
                PluginDiscovererInterface::class => PluginDiscoverer::class,
                PluginRepositoryInterface::class => PluginRepository::class,
                ConfigWriterInterface::class => ConfigWriter::class,
                PluginConfigReaderInterface::class => PluginConfigReader::class,
                MigrationRunnerInterface::class => MigrationRunner::class,
                SeederRunnerInterface::class => SeederRunner::class,
            ],
            // 发布配置文件
            'publish' => [
                [
                    'id' => 'config',
                    'description' => '插件管理配置文件',
                    'source' => __DIR__ . '/../publish/plugins.php',
                    'destination' => BASE_PATH . '/config/autoload/plugins.php',
                ],
            ],
        ];
    }
}
