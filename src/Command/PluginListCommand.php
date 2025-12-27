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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;

/**
 * 插件列表命令.
 *
 * 用于列出所有可用插件，支持状态过滤、JSON 输出和详细模式。
 *
 * @see Requirements 7.1, 7.2, 7.3, 7.4, 8.4
 */
#[Command]
class PluginListCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:list';

    protected string $description = 'List all available plugins';

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $statusFilter = $this->input->getOption('status');
        $jsonOutput = $this->input->getOption('json');
        $verbose = $this->output->isVerbose();

        $discoverer = $this->container->get(PluginDiscovererInterface::class);

        // 获取所有插件
        $localPlugins = $discoverer->discoverLocalPlugins();
        $installedPlugins = $discoverer->getInstalledPlugins();

        // 合并插件列表
        $plugins = $this->mergePluginLists($localPlugins, $installedPlugins, $discoverer);

        // 应用状态过滤
        if ($statusFilter !== null) {
            $plugins = $this->filterByStatus($plugins, $statusFilter);
        }

        // 检查是否有插件
        if (empty($plugins)) {
            if ($jsonOutput) {
                $this->line(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $this->info('No plugins found.');
                if ($statusFilter !== null) {
                    $this->line('Try removing the --status filter to see all plugins.');
                }
            }
            return self::SUCCESS;
        }

        // 输出
        if ($jsonOutput) {
            $this->outputJson($plugins);
        } else {
            $this->outputTable($plugins, $verbose);
        }

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addOption(
            'status',
            's',
            InputOption::VALUE_REQUIRED,
            'Filter by status: installed, enabled, disabled, available'
        );
        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Output in JSON format'
        );
    }

    /**
     * 合并本地插件和已安装插件列表.
     */
    private function mergePluginLists(
        array $localPlugins,
        array $installedPlugins,
        PluginDiscovererInterface $discoverer
    ): array {
        $plugins = [];

        // 添加本地插件
        foreach ($localPlugins as $plugin) {
            $name = $plugin['name'];
            $plugins[$name] = [
                'name' => $name,
                'version' => $plugin['version'] ?? 'N/A',
                'description' => $plugin['description'] ?? '',
                'author' => $plugin['author'] ?? '',
                'priority' => $plugin['priority'] ?? 0,
                'dependencies' => $plugin['dependencies'] ?? [],
                'installed' => $plugin['installed'] ?? false,
                'enabled' => $plugin['enabled'] ?? false,
                'path' => $plugin['path'] ?? '',
            ];
        }

        // 添加/更新已安装插件
        foreach ($installedPlugins as $name => $info) {
            $pluginConfig = $discoverer->getPluginJsonConfig($name);

            if (! isset($plugins[$name])) {
                $plugins[$name] = [
                    'name' => $name,
                    'version' => $info['version'] ?? 'N/A',
                    'description' => $pluginConfig['description'] ?? '',
                    'author' => $pluginConfig['author'] ?? '',
                    'priority' => $pluginConfig['priority'] ?? 0,
                    'dependencies' => $pluginConfig['dependencies'] ?? [],
                    'installed' => true,
                    'enabled' => $discoverer->isEnabled($name),
                    'path' => $info['path'] ?? '',
                ];
            } else {
                // 更新已有条目
                $plugins[$name]['installed'] = true;
                $plugins[$name]['enabled'] = $discoverer->isEnabled($name);
                $plugins[$name]['version'] = $info['version'] ?? $plugins[$name]['version'];
            }
        }

        // 按名称排序
        ksort($plugins);

        return array_values($plugins);
    }

    /**
     * 按状态过滤插件.
     */
    private function filterByStatus(array $plugins, string $status): array
    {
        return array_filter($plugins, function (array $plugin) use ($status): bool {
            return match (strtolower($status)) {
                'installed' => $plugin['installed'],
                'enabled' => $plugin['installed'] && $plugin['enabled'],
                'disabled' => $plugin['installed'] && ! $plugin['enabled'],
                'available' => ! $plugin['installed'],
                default => true,
            };
        });
    }

    /**
     * 输出 JSON 格式.
     */
    private function outputJson(array $plugins): void
    {
        $this->line(json_encode($plugins, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 输出表格格式.
     */
    private function outputTable(array $plugins, bool $verbose): void
    {
        $table = new Table($this->output);

        if ($verbose) {
            $table->setHeaders(['Name', 'Version', 'Status', 'Description', 'Author', 'Dependencies']);
        } else {
            $table->setHeaders(['Name', 'Version', 'Status', 'Description']);
        }

        foreach ($plugins as $plugin) {
            $status = $this->formatStatus($plugin['installed'], $plugin['enabled']);

            $row = [
                $plugin['name'],
                $plugin['version'],
                $status,
                $this->truncate($plugin['description'], 40),
            ];

            if ($verbose) {
                $row[] = $plugin['author'] ?: '-';
                $row[] = ! empty($plugin['dependencies'])
                    ? implode(', ', $plugin['dependencies'])
                    : '-';
            }

            $table->addRow($row);
        }

        $table->render();

        // 显示统计信息
        $this->line('');
        $installed = count(array_filter($plugins, fn ($p) => $p['installed']));
        $enabled = count(array_filter($plugins, fn ($p) => $p['installed'] && $p['enabled']));
        $available = count(array_filter($plugins, fn ($p) => ! $p['installed']));

        $this->line('Total: ' . count($plugins) . ' plugins');
        $this->line("  Installed: {$installed} (Enabled: {$enabled}, Disabled: " . ($installed - $enabled) . ')');
        $this->line("  Available: {$available}");
    }

    /**
     * 格式化状态显示.
     */
    private function formatStatus(bool $installed, bool $enabled): string
    {
        if (! $installed) {
            return '<fg=gray>Available</>';
        }

        return $enabled
            ? '<info>Enabled</info>'
            : '<comment>Disabled</comment>';
    }

    /**
     * 截断字符串.
     */
    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }
}
