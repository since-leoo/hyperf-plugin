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
 * 插件启用命令
 * 
 * 用于启用已安装的插件。
 * 
 * @see Requirements 4.1, 4.3, 4.5
 */
#[Command]
class PluginEnableCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:enable';

    protected string $description = 'Enable an installed plugin';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin package name to enable');
    }

    public function handle(): int
    {
        $pluginName = $this->input->getArgument('pluginName');

        $pluginManager = $this->container->get(PluginManagerInterface::class);
        $discoverer = $this->container->get(PluginDiscovererInterface::class);

        // 检查插件是否已安装
        if (!$discoverer->isInstalled($pluginName)) {
            $this->error("Plugin '{$pluginName}' is not installed.");
            $this->line('');
            $this->line("Run 'php bin/hyperf.php plugin:install {$pluginName}' to install it first.");
            return self::FAILURE;
        }

        // 检查是否已启用
        if ($discoverer->isEnabled($pluginName)) {
            $this->info("Plugin '{$pluginName}' is already enabled.");
            return self::SUCCESS;
        }

        // 检查依赖
        $missingDeps = $pluginManager->checkDependencies($pluginName);
        if (!empty($missingDeps)) {
            $this->error("Cannot enable plugin '{$pluginName}': missing dependencies:");
            foreach ($missingDeps as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
            $this->line('Please install and enable the required dependencies first.');
            return self::FAILURE;
        }

        // 检查依赖的插件是否已启用
        $pluginConfig = $discoverer->getPluginJsonConfig($pluginName);
        $dependencies = $pluginConfig['dependencies'] ?? [];
        $disabledDeps = [];
        
        foreach ($dependencies as $dep) {
            if ($discoverer->isInstalled($dep) && !$discoverer->isEnabled($dep)) {
                $disabledDeps[] = $dep;
            }
        }

        if (!empty($disabledDeps)) {
            $this->warn("Warning: The following dependencies are installed but not enabled:");
            foreach ($disabledDeps as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
            $this->line('Consider enabling them first for full functionality.');
        }

        $this->info("Enabling plugin '{$pluginName}'...");

        // 执行启用
        if ($pluginManager->enable($pluginName)) {
            $this->info("Plugin '{$pluginName}' enabled successfully.");
            $this->line('');
            $this->line('Note: You may need to restart the server for changes to take effect.');
            return self::SUCCESS;
        }

        $this->error("Failed to enable plugin '{$pluginName}'.");
        $this->line('Check the logs for more details.');
        return self::FAILURE;
    }
}
