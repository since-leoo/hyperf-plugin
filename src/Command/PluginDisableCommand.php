<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * 插件禁用命令
 * 
 * 用于禁用已启用的插件，会显示依赖警告。
 * 
 * @see Requirements 4.2, 4.4, 4.6, 8.2
 */
#[Command]
class PluginDisableCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:disable';

    protected string $description = 'Disable an enabled plugin';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin package name to disable');
    }

    public function handle(): int
    {
        $pluginName = $this->input->getArgument('pluginName');

        $pluginManager = $this->container->get(PluginManagerInterface::class);
        $discoverer = $this->container->get(PluginDiscovererInterface::class);

        // 检查插件是否已安装
        if (!$discoverer->isInstalled($pluginName)) {
            $this->error("Plugin '{$pluginName}' is not installed.");
            return self::FAILURE;
        }

        // 检查是否已禁用
        if (!$discoverer->isEnabled($pluginName)) {
            $this->info("Plugin '{$pluginName}' is already disabled.");
            return self::SUCCESS;
        }

        // 检查是否有其他启用的插件依赖此插件
        $dependents = $this->getEnabledDependentPlugins($pluginName, $discoverer);
        if (!empty($dependents)) {
            $this->warn("Warning: The following enabled plugins depend on '{$pluginName}':");
            foreach ($dependents as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
            $this->line('Disabling this plugin may cause issues with the dependent plugins.');
            $this->line('');
        }

        $this->info("Disabling plugin '{$pluginName}'...");

        // 执行禁用
        if ($pluginManager->disable($pluginName)) {
            $this->info("Plugin '{$pluginName}' disabled successfully.");
            $this->line('');
            $this->line('Note: You may need to restart the server for changes to take effect.');
            return self::SUCCESS;
        }

        $this->error("Failed to disable plugin '{$pluginName}'.");
        $this->line('Check the logs for more details.');
        return self::FAILURE;
    }

    /**
     * 获取依赖指定插件的所有已启用插件
     */
    private function getEnabledDependentPlugins(string $packageName, PluginDiscovererInterface $discoverer): array
    {
        $dependents = [];
        $installedPlugins = $discoverer->getInstalledPlugins();

        foreach ($installedPlugins as $installedPackage => $info) {
            if ($installedPackage === $packageName) {
                continue;
            }

            // 只检查已启用的插件
            if (!$discoverer->isEnabled($installedPackage)) {
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
