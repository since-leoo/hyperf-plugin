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

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;
use SinceLeoo\Plugin\Contract\MigrationRunnerInterface;
use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use SinceLeoo\Plugin\Contract\PluginRepositoryInterface;
use SinceLeoo\Plugin\Contract\SeederRunnerInterface;
use SinceLeoo\Plugin\Event\PluginBootedEvent;
use SinceLeoo\Plugin\Event\PluginInstalledEvent;
use SinceLeoo\Plugin\Event\PluginMigratedEvent;
use SinceLeoo\Plugin\Event\PluginMigrationRolledBackEvent;
use SinceLeoo\Plugin\Event\PluginSeededEvent;
use SinceLeoo\Plugin\Event\PluginUninstalledEvent;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 插件管理器 - 协调所有插件操作.
 *
 * 负责插件的完整生命周期管理，包括安装、卸载、启用、禁用、
 * 加载等操作。实现错误隔离，单个插件失败不影响其他插件。
 *
 * @see Requirements 3.3, 3.4, 9.2, 12.1, 12.2, 12.3
 */
class PluginManager implements PluginManagerInterface
{
    /**
     * 已加载的插件列表.
     *
     * @var array<string, array>
     */
    private array $loadedPlugins = [];

    public function __construct(
        private PluginDiscovererInterface $discoverer,
        private PluginRepositoryInterface $repository,
        private ConfigWriterInterface $configWriter,
        private PluginConfigReaderInterface $configReader,
        private MigrationRunnerInterface $migrationRunner,
        private SeederRunnerInterface $seederRunner,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * 安装流程：自动安装依赖 → migrations → seeders → plugin install hook
     *
     * @see Requirements 5.1, 5.3, 5.4, 10.1, 10.6, 10.7, 16.1, 16.2, 16.3
     */
    public function install(string $packageName, array $options = []): bool
    {
        try {
            // 1. 获取插件路径
            $pluginPath = $this->discoverer->getPluginPath($packageName);
            if ($pluginPath === null) {
                $this->log('error', "Plugin not found: {$packageName}");
                return false;
            }

            // 2. 验证 plugin.json
            $pluginConfig = $this->configReader->read($pluginPath);
            $errors = $this->configReader->validate($pluginConfig);
            if (! empty($errors)) {
                $this->log('error', "Invalid plugin.json for {$packageName}", ['errors' => $errors]);
                return false;
            }

            // 3. 检查依赖（安装状态和启用状态）
            $autoInstallDeps = $options['auto_install_deps'] ?? true;
            $depCheck = $this->checkDependencies($packageName);

            // 3.1 检查是否有未启用的依赖（必须先启用才能安装）
            if (! empty($depCheck['disabled'])) {
                $this->log('error', "Dependencies not enabled for {$packageName}", ['disabled' => $depCheck['disabled']]);
                return false;
            }

            // 3.2 处理未安装的依赖
            if (! empty($depCheck['missing'])) {
                if ($autoInstallDeps) {
                    $this->log('info', "Auto-installing dependencies for {$packageName}", ['dependencies' => $depCheck['missing']]);

                    foreach ($depCheck['missing'] as $depPackage) {
                        // 检查依赖插件是否已启用（在 plugin.json 中）
                        if (! $this->discoverer->isEnabled($depPackage)) {
                            $this->log('error', "Dependency plugin not enabled: {$depPackage}. Please set enabled: true in its plugin.json first.");
                            return false;
                        }

                        $this->log('info', "Installing dependency: {$depPackage}");

                        // 递归安装依赖（传递 auto_install_deps 选项）
                        $depResult = $this->install($depPackage, ['auto_install_deps' => true]);

                        if (! $depResult) {
                            $this->log('error', "Failed to install dependency: {$depPackage}");
                            return false;
                        }

                        $this->log('info', "Dependency installed successfully: {$depPackage}");
                    }
                } else {
                    $this->log('error', "Missing dependencies for {$packageName}", ['missing' => $depCheck['missing']]);
                    return false;
                }
            }

            $executedMigrations = [];
            $seederExecuted = false;

            // 4. 安装插件声明的 Composer 依赖（安装到主项目 vendor，但不修改主项目 composer.json）
            $composerDeps = $this->configReader->get($pluginConfig, 'composer_require', []);
            if (! empty($composerDeps)) {
                $this->log('info', "Installing composer dependencies for {$packageName}", ['dependencies' => $composerDeps]);
                $composerResult = $this->installComposerPackages($composerDeps);
                if (! $composerResult) {
                    $this->log('error', "Failed to install composer dependencies for {$packageName}");
                    return false;
                }
            }

            // 5. 执行迁移
            $skipMigrations = $options['skip_migrations'] ?? false;
            if (! $skipMigrations && $this->configReader->hasMigrations($pluginPath)) {
                $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                $this->log('info', "Executing migrations from: {$migrationPath}");
                try {
                    $executedMigrations = $this->migrationRunner->migrate($packageName, $migrationPath);

                    if (! empty($executedMigrations)) {
                        $this->log('info', 'Executed migrations', ['migrations' => $executedMigrations]);
                        $this->dispatch(new PluginMigratedEvent($packageName, $pluginConfig, $executedMigrations));
                    }
                } catch (Throwable $e) {
                    $this->log('error', "Migration failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    // 回滚已执行的迁移
                    if (! empty($executedMigrations)) {
                        $this->migrationRunner->rollback($packageName, $migrationPath);
                    }
                    return false;
                }
            } elseif ($skipMigrations) {
                $this->log('info', "Migrations skipped for {$packageName}");
            }

            // 6. 执行填充器（非阻塞）
            $skipSeeders = $options['skip_seeders'] ?? false;
            if (! $skipSeeders && $this->configReader->hasSeeders($pluginPath)) {
                $seederPath = $this->configReader->getSeederPath($pluginPath);
                $this->log('info', "Executing seeders from: {$seederPath}");
                try {
                    $seederExecuted = $this->seederRunner->seed($packageName, $seederPath);

                    if ($seederExecuted) {
                        $seeders = $this->seederRunner->discoverSeeders($seederPath);
                        foreach ($seeders as $seeder) {
                            $this->dispatch(new PluginSeededEvent($packageName, $pluginConfig, $seeder));
                        }
                    }
                } catch (Throwable $e) {
                    // 填充器失败不阻塞安装
                    $this->log('warning', "Seeder failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                }
            } elseif ($skipSeeders) {
                $this->log('info', "Seeders skipped for {$packageName}");
            }

            // 7. 调用插件 install 钩子
            $pluginClass = $this->discoverer->getPluginClass($packageName);
            if ($pluginClass !== null && class_exists($pluginClass)) {
                try {
                    $plugin = new $pluginClass();
                    if ($plugin instanceof PluginInterface) {
                        $plugin->install();
                    }
                } catch (Throwable $e) {
                    $this->log('error', "Plugin install hook failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    // 回滚迁移
                    if (! empty($executedMigrations) && $this->configReader->hasMigrations($pluginPath)) {
                        $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                        $this->migrationRunner->rollback($packageName, $migrationPath);
                    }
                    return false;
                }
            }

            // 8. 创建 install.lock 文件标记安装成功
            $lockFile = $pluginPath . '/install.lock';
            $lockContent = [
                'installed_at' => date('Y-m-d H:i:s'),
                'version' => $pluginConfig['version'] ?? '1.0.0',
                'migrations_executed' => $executedMigrations,
                'seeder_executed' => $seederExecuted,
            ];
            file_put_contents($lockFile, json_encode($lockContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // 9. 触发安装完成事件
            $this->dispatch(new PluginInstalledEvent($packageName, $pluginConfig));

            $this->log('info', "Plugin installed successfully: {$packageName}");
            return true;
        } catch (Throwable $e) {
            $this->log('error', "Installation failed for {$packageName}", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * @see Requirements 6.1, 6.2, 6.3, 6.4, 8.3, 10.2, 15.1-15.7
     */
    public function uninstall(string $packageName, bool $force = false, ?bool $rollback = null): bool
    {
        try {
            // 1. 检查插件是否已安装
            if (! $this->discoverer->isInstalled($packageName)) {
                $this->log('error', "Plugin not installed: {$packageName}");
                return false;
            }

            // 2. 检查依赖关系（除非强制卸载）
            if (! $force) {
                $dependents = $this->getDependentPlugins($packageName);
                if (! empty($dependents)) {
                    $this->log('error', "Cannot uninstall {$packageName}: other plugins depend on it", [
                        'dependents' => $dependents,
                    ]);
                    return false;
                }
            }

            // 3. 获取插件信息
            $pluginPath = $this->discoverer->getPluginPath($packageName);
            $pluginConfig = $pluginPath ? $this->configReader->read($pluginPath) : [];
            $installedConfig = $this->discoverer->getInstalledPlugins()[$packageName] ?? [];

            // 4. 调用插件 uninstall 钩子
            $pluginClass = $installedConfig['plugin_class'] ?? $this->discoverer->getPluginClass($packageName);
            if ($pluginClass !== null && class_exists($pluginClass)) {
                try {
                    $plugin = new $pluginClass();
                    if ($plugin instanceof PluginInterface) {
                        $plugin->uninstall();
                    }
                } catch (Throwable $e) {
                    $this->log('warning', "Plugin uninstall hook failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    // 继续卸载流程
                }
            }

            // 5. 决定是否回滚迁移
            $shouldRollback = $rollback;
            if ($shouldRollback === null) {
                $shouldRollback = $this->configReader->get($pluginConfig, 'rollback_on_uninstall', false);
            }

            if ($shouldRollback && $pluginPath !== null && $this->configReader->hasMigrations($pluginPath)) {
                $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                try {
                    $rolledBackMigrations = $this->migrationRunner->rollback($packageName, $migrationPath);

                    if (! empty($rolledBackMigrations)) {
                        $this->dispatch(new PluginMigrationRolledBackEvent($packageName, $pluginConfig, $rolledBackMigrations));
                    }
                } catch (Throwable $e) {
                    $this->log('error', "Migration rollback failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    if (! $force) {
                        return false;
                    }
                    // 强制卸载时继续
                }
            }

            // 6. 删除 install.lock 文件
            if ($pluginPath !== null) {
                $lockFile = $pluginPath . '/install.lock';
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
            }

            // 7. 触发卸载完成事件
            $this->dispatch(new PluginUninstalledEvent($packageName, $pluginConfig));

            $this->log('info', "Plugin uninstalled successfully: {$packageName}");
            return true;
        } catch (Throwable $e) {
            $this->log('error', "Uninstallation failed for {$packageName}", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * 按优先级顺序加载插件，单个插件失败不影响其他插件。
     */
    public function bootPlugins(): void
    {
        $installedPlugins = $this->discoverer->getInstalledPlugins();

        // 收集所有已安装且已启用的插件及其优先级
        $pluginsToLoad = [];
        foreach ($installedPlugins as $packageName => $pluginInfo) {
            // 从 plugin.json 读取 enabled 状态
            if (! $this->discoverer->isEnabled($packageName)) {
                continue;
            }

            $pluginPath = $pluginInfo['path'] ?? $this->discoverer->getPluginPath($packageName);
            $pluginConfig = $pluginPath ? $this->configReader->read($pluginPath) : [];
            $priority = $this->configReader->get($pluginConfig, 'priority', 0);

            $pluginsToLoad[$packageName] = [
                'info' => $pluginInfo,
                'config' => $pluginConfig,
                'priority' => $priority,
            ];
        }

        // 按优先级排序（降序，数值越大越先加载）
        uasort($pluginsToLoad, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            return 0;
        });

        // 加载插件
        foreach ($pluginsToLoad as $packageName => $data) {
            try {
                $this->loadPlugin($packageName, $data['info'], $data['config']);
            } catch (Throwable $e) {
                // 错误隔离：单个插件失败不影响其他插件
                $this->log('error', "Failed to boot plugin: {$packageName}", [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    public function getLoadedPlugins(): array
    {
        return $this->loadedPlugins;
    }

    /**
     * 检查插件依赖.
     *
     * 返回两类问题依赖：
     * - missing: 未安装的依赖
     * - disabled: 已安装但未启用的依赖
     *
     * @see Requirements 8.1
     * @return array{missing: array, disabled: array}
     */
    public function checkDependencies(string $packageName): array
    {
        $pluginPath = $this->discoverer->getPluginPath($packageName);
        if ($pluginPath === null) {
            return ['missing' => [], 'disabled' => []];
        }

        $pluginConfig = $this->configReader->read($pluginPath);
        $dependencies = $this->configReader->get($pluginConfig, 'dependencies', []);

        if (empty($dependencies)) {
            return ['missing' => [], 'disabled' => []];
        }

        $missing = [];
        $disabled = [];

        foreach ($dependencies as $depPackage) {
            if (! $this->discoverer->isInstalled($depPackage)) {
                $missing[] = $depPackage;
            } elseif (! $this->discoverer->isEnabled($depPackage)) {
                $disabled[] = $depPackage;
            }
        }

        return ['missing' => $missing, 'disabled' => $disabled];
    }

    /**
     * 加载单个插件.
     *
     * @param string $packageName 插件包名
     * @param array $pluginInfo 插件安装信息
     * @param array $pluginConfig plugin.json 配置
     */
    private function loadPlugin(string $packageName, array $pluginInfo, array $pluginConfig): void
    {
        // 注册 ConfigProvider
        $configProvider = $this->discoverer->getPluginConfigProvider($packageName);
        if ($configProvider !== null && class_exists($configProvider)) {
            $providerConfig = (new $configProvider())();
            $this->registerPluginConfig($providerConfig);
        }

        // 实例化并注册插件
        $pluginClass = $pluginInfo['plugin_class'] ?? $this->discoverer->getPluginClass($packageName);
        if ($pluginClass !== null && class_exists($pluginClass)) {
            $plugin = new $pluginClass();
            if ($plugin instanceof PluginInterface) {
                $this->repository->register($packageName, $plugin);

                // 调用 boot 方法
                try {
                    $plugin->boot();
                    $this->dispatch(new PluginBootedEvent($packageName, $pluginConfig));
                } catch (Throwable $e) {
                    $this->log('error', "Plugin boot method failed: {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    // 继续，不影响其他插件
                }
            }
        }

        $this->loadedPlugins[$packageName] = $pluginInfo;
    }

    /**
     * 注册插件的 ConfigProvider 配置.
     *
     * @param array $config ConfigProvider 返回的配置数组
     */
    private function registerPluginConfig(array $config): void
    {
        // 依赖注入、命令、监听器等会在 Hyperf 启动时自动注册
        // 这里主要是为了兼容性和扩展性保留
    }

    /**
     * 获取依赖指定插件的所有已安装插件.
     *
     * @param string $packageName 插件包名
     * @return array 依赖此插件的插件包名列表
     */
    private function getDependentPlugins(string $packageName): array
    {
        $dependents = [];
        $installedPlugins = $this->discoverer->getInstalledPlugins();

        foreach ($installedPlugins as $installedPackage => $info) {
            if ($installedPackage === $packageName) {
                continue;
            }

            $pluginPath = $info['path'] ?? $this->discoverer->getPluginPath($installedPackage);
            if ($pluginPath === null) {
                continue;
            }

            $pluginConfig = $this->configReader->read($pluginPath);
            $dependencies = $this->configReader->get($pluginConfig, 'dependencies', []);

            if (in_array($packageName, $dependencies, true)) {
                $dependents[] = $installedPackage;
            }
        }

        return $dependents;
    }

    /**
     * 获取依赖指定插件的所有已启用插件.
     *
     * @param string $packageName 插件包名
     * @return array 依赖此插件的已启用插件包名列表
     */
    private function getEnabledDependentPlugins(string $packageName): array
    {
        $dependents = $this->getDependentPlugins($packageName);

        return array_filter($dependents, function (string $depPackage): bool {
            return $this->discoverer->isEnabled($depPackage);
        });
    }

    /**
     * 分发事件.
     *
     * @param object $event 事件对象
     */
    private function dispatch(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * 记录日志.
     *
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->{$level}($message, $context);
        }
    }

    /**
     * 安装 Composer 包到主项目 vendor 目录.
     *
     * 使用 composer require 安装包，依赖会被记录到主项目的 composer.json 中，
     * 这样 composer update 时不会丢失这些依赖。
     *
     * @param array $packages 包列表，格式: ['guzzlehttp/guzzle' => '^7.0'] 或 ['guzzlehttp/guzzle']
     * @return bool 安装是否成功
     */
    private function installComposerPackages(array $packages): bool
    {
        if (empty($packages)) {
            return true;
        }

        $basePath = $this->discoverer->getBasePath();
        $composerJsonPath = $basePath . '/composer.json';

        if (! file_exists($composerJsonPath)) {
            $this->log('error', "composer.json not found at: {$composerJsonPath}");
            return false;
        }

        try {
            // 构建包列表
            $packageArgs = [];
            foreach ($packages as $key => $value) {
                if (is_string($key)) {
                    $packageArgs[] = "{$key}:{$value}";
                } else {
                    $packageArgs[] = $value;
                }
            }

            // 检查包是否已安装
            $packagesToInstall = [];
            foreach ($packageArgs as $packageArg) {
                $packageName = explode(':', $packageArg)[0];
                if (! $this->isComposerPackageInstalled($packageName, $basePath)) {
                    $packagesToInstall[] = $packageArg;
                } else {
                    $this->log('info', "Composer package already installed: {$packageName}");
                }
            }

            if (empty($packagesToInstall)) {
                $this->log('info', 'All composer packages already installed');
                return true;
            }

            if (! class_exists(Process::class)) {
                $this->log('error', 'symfony/process is not available');
                return false;
            }

            // 使用 composer require 安装包（依赖会被记录到 composer.json）
            $command = array_merge(
                ['composer', 'require', '--no-interaction', '--no-scripts'],
                $packagesToInstall
            );

            $this->log('info', 'Running: ' . implode(' ', $command));

            $process = new Process($command, $basePath);
            $process->setTimeout(300);

            $output = '';
            $errorOutput = '';

            $process->run(function ($type, $buffer) use (&$output, &$errorOutput) {
                if ($type === Process::ERR) {
                    $errorOutput .= $buffer;
                } else {
                    $output .= $buffer;
                }
            });

            if (! $process->isSuccessful()) {
                $this->log('error', 'Composer require failed', [
                    'exit_code' => $process->getExitCode(),
                    'error' => $errorOutput ?: $process->getErrorOutput(),
                    'output' => $output,
                ]);
                return false;
            }

            $this->log('info', 'Composer packages installed and recorded in composer.json');
            return true;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to install composer packages', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 检查 Composer 包是否已安装.
     *
     * @param string $packageName 包名
     * @param string $basePath 项目根路径
     */
    private function isComposerPackageInstalled(string $packageName, string $basePath): bool
    {
        $installedJsonPath = $basePath . '/vendor/composer/installed.json';
        if (! file_exists($installedJsonPath)) {
            return false;
        }

        $installed = json_decode(file_get_contents($installedJsonPath), true);
        if (! is_array($installed)) {
            return false;
        }

        // Composer 2.x 格式
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            if (($package['name'] ?? '') === $packageName) {
                return true;
            }
        }

        return false;
    }
}
