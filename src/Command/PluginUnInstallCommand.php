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
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 插件卸载命令.
 *
 * 用于卸载已安装的插件，支持回滚数据库迁移选项。
 *
 * @see Requirements 6.1, 6.2, 6.3, 6.4, 6.5, 15.5, 15.6
 */
#[Command]
class PluginUnInstallCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:uninstall';

    protected string $description = 'Uninstall a plugin from the project';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pluginName = $this->input->getArgument('pluginName');
        $force = $this->input->getOption('force');
        $rollbackOption = $this->input->getOption('rollback');
        $noRollbackOption = $this->input->getOption('no-rollback');

        // 验证选项互斥
        if ($rollbackOption && $noRollbackOption) {
            $this->error('Options --rollback and --no-rollback are mutually exclusive.');
            return self::FAILURE;
        }

        $pluginManager = $this->container->get(PluginManagerInterface::class);
        $discoverer = $this->container->get(PluginDiscovererInterface::class);

        // 检查插件是否已安装
        if (! $discoverer->isInstalled($pluginName)) {
            $this->error("Plugin '{$pluginName}' is not installed.");
            return self::FAILURE;
        }

        // 获取插件配置
        $pluginConfig = $discoverer->getPluginJsonConfig($pluginName);

        // 检查依赖关系
        $dependents = $this->getDependentPlugins($pluginName, $discoverer);
        if (! empty($dependents) && ! $force) {
            $this->error("Cannot uninstall '{$pluginName}': other plugins depend on it:");
            foreach ($dependents as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
            $this->line('Use --force to uninstall anyway.');
            return self::FAILURE;
        }

        if (! empty($dependents) && $force) {
            $this->warn("Warning: The following plugins depend on '{$pluginName}':");
            foreach ($dependents as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
        }

        // 确定是否回滚迁移
        $rollback = null;
        if ($rollbackOption) {
            $rollback = true;
        } elseif ($noRollbackOption) {
            $rollback = false;
        }

        // 显示回滚信息
        $willRollback = $rollback ?? ($pluginConfig['rollback_on_uninstall'] ?? false);
        if ($willRollback) {
            $this->warn('Database migrations will be rolled back.');
        } else {
            $this->info('Database tables will be preserved.');
        }

        $this->info("Uninstalling plugin '{$pluginName}'...");

        // 执行卸载
        if ($pluginManager->uninstall($pluginName, $force, $rollback)) {
            $this->info("Plugin '{$pluginName}' uninstalled successfully.");
            return self::SUCCESS;
        }

        $this->error("Failed to uninstall plugin '{$pluginName}'.");
        $this->line('Check the logs for more details.');
        return self::FAILURE;
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin package name to uninstall');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force uninstall even if other plugins depend on it');
        $this->addOption('rollback', null, InputOption::VALUE_NONE, 'Rollback database migrations (overrides plugin.json setting)');
        $this->addOption('no-rollback', null, InputOption::VALUE_NONE, 'Skip database migration rollback (overrides plugin.json setting)');
    }

    /**
     * 获取依赖指定插件的所有已安装插件.
     */
    private function getDependentPlugins(string $packageName, PluginDiscovererInterface $discoverer): array
    {
        $dependents = [];
        $installedPlugins = $discoverer->getInstalledPlugins();

        foreach ($installedPlugins as $installedPackage => $info) {
            if ($installedPackage === $packageName) {
                continue;
            }

            $pluginConfig = $discoverer->getPluginJsonConfig($installedPackage);
            $dependencies = $pluginConfig['dependencies'] ?? [];

            if (in_array($packageName, $dependencies, true)) {
                $dependents[] = $installedPackage;
            }
        }

        return $dependents;
    }
}
