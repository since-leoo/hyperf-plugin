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
use Throwable;

/**
 * 插件安装命令.
 *
 * 用于安装项目 plugins 目录中的插件或远程仓库的插件。
 *
 * @see Requirements 5.1, 5.2, 5.5, 5.6
 */
#[Command]
class PluginInstallCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:install';

    protected string $description = 'Install a plugin from project plugins directory or remote repository';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pluginName = $this->input->getArgument('pluginName');
        $force = $this->input->getOption('force');

        $pluginManager = $this->container->get(PluginManagerInterface::class);
        $discoverer = $this->container->get(PluginDiscovererInterface::class);

        // 检查插件是否存在
        $pluginPath = $discoverer->getPluginPath($pluginName);
        if ($pluginPath === null) {
            // 尝试从本地插件目录查找
            $localPlugins = $discoverer->discoverLocalPlugins();
            $found = false;
            foreach ($localPlugins as $plugin) {
                if ($plugin['name'] === $pluginName) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $this->error("Plugin '{$pluginName}' not found in project plugins directory or vendor.");
                $this->line('');
                $this->line('Available local plugins:');
                foreach ($localPlugins as $plugin) {
                    $this->line("  - {$plugin['name']}");
                }
                return self::FAILURE;
            }
        }

        // 检查是否已安装
        if ($discoverer->isInstalled($pluginName)) {
            if (! $force) {
                $this->error("Plugin '{$pluginName}' is already installed.");
                $this->line('Use --force to reinstall.');
                return self::FAILURE;
            }

            $this->warn("Plugin '{$pluginName}' is already installed. Reinstalling...");

            // 先卸载
            if (! $pluginManager->uninstall($pluginName, true)) {
                $this->error('Failed to uninstall existing plugin for reinstallation.');
                return self::FAILURE;
            }
        }

        // 验证 plugin.json
        $pluginConfig = $discoverer->getPluginJsonConfig($pluginName);
        if (empty($pluginConfig)) {
            $this->error("Plugin '{$pluginName}' does not have a valid plugin.json file.");
            return self::FAILURE;
        }

        // 检查依赖（如果有缺失依赖，会自动安装；如果有未启用依赖，则报错）
        $depCheck = $pluginManager->checkDependencies($pluginName);

        // 检查未启用的依赖
        if (! empty($depCheck['disabled'])) {
            $this->error("Plugin '{$pluginName}' has dependencies that are not enabled:");
            foreach ($depCheck['disabled'] as $dep) {
                $this->line("  - {$dep}");
            }
            $this->line('');
            $this->line('Please enable these plugins in their plugin.json files first (set "enabled": true).');
            return self::FAILURE;
        }

        // 检查未安装的依赖
        if (! empty($depCheck['missing'])) {
            // 检查这些依赖是否已启用
            foreach ($depCheck['missing'] as $dep) {
                if (! $discoverer->isEnabled($dep)) {
                    $this->error("Dependency plugin '{$dep}' is not enabled.");
                    $this->line('Please set "enabled": true in its plugin.json first.');
                    return self::FAILURE;
                }
            }

            $this->warn("Plugin '{$pluginName}' has dependencies that will be auto-installed:");
            foreach ($depCheck['missing'] as $dep) {
                $this->line("  - {$dep}");
            }
        }

        // 构建安装选项
        $options = [
            'auto_install_deps' => true,
            'skip_migrations' => $this->input->getOption('no-migrate'),
            'skip_seeders' => $this->input->getOption('no-seed'),
        ];

        $this->info("Installing plugin '{$pluginName}'...");

        // 执行安装
        try {
            $result = $pluginManager->install($pluginName, $options);

            if ($result) {
                $this->info("Plugin '{$pluginName}' installed successfully.");

                // 显示插件信息
                $this->displayPluginInfo($pluginConfig);

                return self::SUCCESS;
            }

            $this->error("Failed to install plugin '{$pluginName}'.");
            $this->line('Check the logs for more details.');
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Installation exception: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function configure(): void
    {
        $this->addArgument('pluginName', InputArgument::REQUIRED, 'The plugin package name to install');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reinstall if already installed');
        $this->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Skip database migrations');
        $this->addOption('no-seed', null, InputOption::VALUE_NONE, 'Skip database seeders');
    }

    /**
     * 显示插件信息.
     */
    private function displayPluginInfo(array $config): void
    {
        $this->line('');
        $this->line('Plugin Information:');
        $this->line("  Name:        {$config['name']}");
        $this->line('  Version:     ' . ($config['version'] ?? 'N/A'));

        if (! empty($config['description'])) {
            $this->line("  Description: {$config['description']}");
        }

        if (! empty($config['author'])) {
            $this->line("  Author:      {$config['author']}");
        }

        $enabled = $config['enabled'] ?? false;
        $status = $enabled ? '<info>Enabled</info>' : '<comment>Disabled</comment>';
        $this->line("  Status:      {$status}");

        if (! $enabled) {
            $this->line('');
            $this->line("Run 'php bin/hyperf.php plugin:enable {$config['name']}' to enable the plugin.");
        }
    }
}
