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

namespace SinceLeoo\Plugin\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\SeederRunnerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 插件填充命令.
 *
 * 用于独立执行插件的数据填充器。
 *
 * @see Requirements 14.6
 */
#[Command]
class PluginSeedCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:seed';

    protected string $description = 'Run seeders for a specific plugin';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pluginName = $this->input->getArgument('pluginName');
        $noProxy = $this->input->getOption('no-proxy');

        $discoverer = $this->container->get(PluginDiscovererInterface::class);
        $configReader = $this->container->get(PluginConfigReaderInterface::class);
        $seederRunner = $this->container->get(SeederRunnerInterface::class);

        // 检查插件是否已安装
        if (! $discoverer->isInstalled($pluginName)) {
            $this->error("Plugin '{$pluginName}' is not installed.");
            return self::FAILURE;
        }

        // 获取插件路径
        $pluginPath = $discoverer->getPluginPath($pluginName);
        if ($pluginPath === null) {
            $this->error("Could not find plugin path for '{$pluginName}'.");
            return self::FAILURE;
        }

        // 检查是否有填充器目录
        if (! $configReader->hasSeeders($pluginPath)) {
            $this->warn("Plugin '{$pluginName}' does not have a seeders directory.");
            $this->line('Expected directory: Database/Seeders');
            return self::SUCCESS;
        }

        $seederPath = $configReader->getSeederPath($pluginPath);
        if ($seederPath === null) {
            $this->error("Could not determine seeder path for '{$pluginName}'.");
            return self::FAILURE;
        }

        // 发现填充器
        $seeders = $seederRunner->discoverSeeders($seederPath);
        if (empty($seeders)) {
            $this->info("No seeders found for plugin '{$pluginName}'.");
            return self::SUCCESS;
        }

        $this->info('Found ' . count($seeders) . " seeder(s) for plugin '{$pluginName}':");
        foreach ($seeders as $seeder) {
            $this->line("  - {$seeder}");
        }
        $this->line('');

        $this->info('Running seeders...');

        // 执行填充器
        $regenerateProxy = ! $noProxy;
        $success = $seederRunner->seed($pluginName, $seederPath, $regenerateProxy);

        if ($success) {
            $this->info("Seeders executed successfully for plugin '{$pluginName}'.");
            return self::SUCCESS;
        }

        $this->warn('Some seeders may have failed. Check the logs for details.');
        return self::SUCCESS; // 填充器失败不阻塞，返回成功
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin package name to seed');
        $this->addOption('no-proxy', null, InputOption::VALUE_NONE, 'Skip proxy class regeneration');
    }
}
