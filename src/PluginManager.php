<?php

declare(strict_types=1);

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
use SinceLeoo\Plugin\Event\PluginDisabledEvent;
use SinceLeoo\Plugin\Event\PluginEnabledEvent;
use SinceLeoo\Plugin\Event\PluginInstalledEvent;
use SinceLeoo\Plugin\Event\PluginMigratedEvent;
use SinceLeoo\Plugin\Event\PluginMigrationRolledBackEvent;
use SinceLeoo\Plugin\Event\PluginSeededEvent;
use SinceLeoo\Plugin\Event\PluginUninstalledEvent;
use Throwable;

/**
 * 插件管理器 - 协调所有插件操作
 * 
 * 负责插件的完整生命周期管理，包括安装、卸载、启用、禁用、
 * 加载等操作。实现错误隔离，单个插件失败不影响其他插件。
 * 
 * @see Requirements 3.3, 3.4, 9.2, 12.1, 12.2, 12.3
 */
class PluginManager implements PluginManagerInterface
{
    /**
     * 已加载的插件列表
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
    ) {}


    /**
     * {@inheritdoc}
     * 
     * 安装流程：composer require → migrations → seeders → plugin install hook
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
            if (!empty($errors)) {
                $this->log('error', "Invalid plugin.json for {$packageName}", ['errors' => $errors]);
                return false;
            }

            // 3. 检查依赖
            $missingDeps = $this->checkDependencies($packageName);
            if (!empty($missingDeps)) {
                $this->log('error', "Missing dependencies for {$packageName}", ['missing' => $missingDeps]);
                return false;
            }

            $executedMigrations = [];
            $seederExecuted = false;

            // 4. 执行迁移
            if ($this->configReader->hasMigrations($pluginPath)) {
                $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                try {
                    $executedMigrations = $this->migrationRunner->migrate($packageName, $migrationPath);
                    
                    if (!empty($executedMigrations)) {
                        $this->dispatch(new PluginMigratedEvent($packageName, $pluginConfig, $executedMigrations));
                    }
                } catch (Throwable $e) {
                    $this->log('error', "Migration failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    // 回滚已执行的迁移
                    if (!empty($executedMigrations)) {
                        $this->migrationRunner->rollback($packageName, $migrationPath);
                    }
                    return false;
                }
            }

            // 5. 执行填充器（非阻塞）
            if ($this->configReader->hasSeeders($pluginPath)) {
                $seederPath = $this->configReader->getSeederPath($pluginPath);
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
            }

            // 6. 调用插件 install 钩子
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
                    if (!empty($executedMigrations) && $this->configReader->hasMigrations($pluginPath)) {
                        $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                        $this->migrationRunner->rollback($packageName, $migrationPath);
                    }
                    return false;
                }
            }

            // 7. 更新配置
            $defaultEnabled = $this->configReader->get($pluginConfig, 'enabled', false);
            $priority = $this->configReader->get($pluginConfig, 'priority', 0);
            
            $installConfig = [
                'version' => $pluginConfig['version'] ?? '1.0.0',
                'path' => $pluginPath,
                'installed_at' => date('Y-m-d H:i:s'),
                'plugin_class' => $pluginClass,
                'migrations_executed' => $executedMigrations,
                'seeder_executed' => $seederExecuted,
            ];
            
            $this->configWriter->updatePluginConfig($packageName, $installConfig);
            $this->configWriter->setPluginEnabled($packageName, $defaultEnabled);

            // 8. 触发安装完成事件
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
     * {@inheritdoc}
     * 
     * @see Requirements 6.1, 6.2, 6.3, 6.4, 8.3, 10.2, 15.1-15.7
     */
    public function uninstall(string $packageName, bool $force = false, ?bool $rollback = null): bool
    {
        try {
            // 1. 检查插件是否已安装
            if (!$this->discoverer->isInstalled($packageName)) {
                $this->log('error', "Plugin not installed: {$packageName}");
                return false;
            }

            // 2. 检查依赖关系（除非强制卸载）
            if (!$force) {
                $dependents = $this->getDependentPlugins($packageName);
                if (!empty($dependents)) {
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

            $rolledBackMigrations = [];
            if ($shouldRollback && $pluginPath !== null && $this->configReader->hasMigrations($pluginPath)) {
                $migrationPath = $this->configReader->getMigrationPath($pluginPath);
                try {
                    $rolledBackMigrations = $this->migrationRunner->rollback($packageName, $migrationPath);
                    
                    if (!empty($rolledBackMigrations)) {
                        $this->dispatch(new PluginMigrationRolledBackEvent($packageName, $pluginConfig, $rolledBackMigrations));
                    }
                } catch (Throwable $e) {
                    $this->log('error', "Migration rollback failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                    if (!$force) {
                        return false;
                    }
                    // 强制卸载时继续
                }
            }

            // 6. 移除配置
            $this->configWriter->removePluginConfig($packageName);

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
     * @see Requirements 4.1, 8.2, 10.3
     */
    public function enable(string $packageName): bool
    {
        try {
            // 检查插件是否已安装
            if (!$this->discoverer->isInstalled($packageName)) {
                $this->log('error', "Cannot enable: plugin not installed: {$packageName}");
                return false;
            }

            // 检查是否已启用
            if ($this->discoverer->isEnabled($packageName)) {
                $this->log('info', "Plugin already enabled: {$packageName}");
                return true;
            }

            // 检查依赖是否都已启用
            $missingDeps = $this->checkDependencies($packageName);
            if (!empty($missingDeps)) {
                $this->log('error', "Cannot enable {$packageName}: missing dependencies", [
                    'missing' => $missingDeps,
                ]);
                return false;
            }

            // 调用插件 enable 钩子
            $pluginClass = $this->discoverer->getPluginClass($packageName);
            if ($pluginClass !== null && class_exists($pluginClass)) {
                try {
                    $plugin = new $pluginClass();
                    if ($plugin instanceof PluginInterface) {
                        $plugin->enable();
                    }
                } catch (Throwable $e) {
                    $this->log('warning', "Plugin enable hook failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            // 更新配置
            $this->configWriter->setPluginEnabled($packageName, true);

            // 获取插件信息并触发事件
            $pluginConfig = $this->discoverer->getPluginJsonConfig($packageName);
            $this->dispatch(new PluginEnabledEvent($packageName, $pluginConfig));

            $this->log('info', "Plugin enabled: {$packageName}");
            return true;

        } catch (Throwable $e) {
            $this->log('error', "Failed to enable plugin: {$packageName}", [
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }


    /**
     * {@inheritdoc}
     * 
     * @see Requirements 4.2, 8.2, 10.4
     */
    public function disable(string $packageName): bool
    {
        try {
            // 检查插件是否已安装
            if (!$this->discoverer->isInstalled($packageName)) {
                $this->log('error', "Cannot disable: plugin not installed: {$packageName}");
                return false;
            }

            // 检查是否已禁用
            if (!$this->discoverer->isEnabled($packageName)) {
                $this->log('info', "Plugin already disabled: {$packageName}");
                return true;
            }

            // 检查是否有其他启用的插件依赖此插件
            $dependents = $this->getEnabledDependentPlugins($packageName);
            if (!empty($dependents)) {
                $this->log('warning', "Disabling {$packageName}: other enabled plugins depend on it", [
                    'dependents' => $dependents,
                ]);
                // 只是警告，不阻止禁用
            }

            // 调用插件 disable 钩子
            $pluginClass = $this->discoverer->getPluginClass($packageName);
            if ($pluginClass !== null && class_exists($pluginClass)) {
                try {
                    $plugin = new $pluginClass();
                    if ($plugin instanceof PluginInterface) {
                        $plugin->disable();
                    }
                } catch (Throwable $e) {
                    $this->log('warning', "Plugin disable hook failed for {$packageName}", [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            // 更新配置
            $this->configWriter->setPluginEnabled($packageName, false);

            // 获取插件信息并触发事件
            $pluginConfig = $this->discoverer->getPluginJsonConfig($packageName);
            $this->dispatch(new PluginDisabledEvent($packageName, $pluginConfig));

            $this->log('info', "Plugin disabled: {$packageName}");
            return true;

        } catch (Throwable $e) {
            $this->log('error', "Failed to disable plugin: {$packageName}", [
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     * 
     * 按优先级顺序加载插件，单个插件失败不影响其他插件。
     * 
     * @see Requirements 3.3, 3.4, 9.2, 12.1, 12.2, 12.3
     */
    public function bootPlugins(): void
    {
        $installedPlugins = $this->discoverer->getInstalledPlugins();
        $config = $this->configWriter->getConfig();
        $enabledConfig = $config['enabled'] ?? [];

        // 收集所有已启用的插件及其优先级
        $pluginsToLoad = [];
        foreach ($installedPlugins as $packageName => $pluginInfo) {
            if (!($enabledConfig[$packageName] ?? false)) {
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

    /**
     * 加载单个插件
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
            $providerConfig = (new $configProvider)();
            $this->registerPluginConfig($providerConfig);
        }

        // 实例化并注册插件
        $pluginClass = $pluginInfo['plugin_class'] ?? $this->discoverer->getPluginClass($packageName);
        if ($pluginClass !== null && class_exists($pluginClass)) {
            $plugin = new $pluginClass();
            if ($plugin instanceof PluginInterface) {
                $this->repository->register($plugin);
                
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
     * 注册插件的 ConfigProvider 配置
     * 
     * @param array $config ConfigProvider 返回的配置数组
     */
    private function registerPluginConfig(array $config): void
    {
        // 依赖注入、命令、监听器等会在 Hyperf 启动时自动注册
        // 这里主要是为了兼容性和扩展性保留
    }

    /**
     * {@inheritdoc}
     */
    public function getLoadedPlugins(): array
    {
        return $this->loadedPlugins;
    }

    /**
     * {@inheritdoc}
     * 
     * @see Requirements 8.1
     */
    public function checkDependencies(string $packageName): array
    {
        $pluginPath = $this->discoverer->getPluginPath($packageName);
        if ($pluginPath === null) {
            return [];
        }

        $pluginConfig = $this->configReader->read($pluginPath);
        $dependencies = $this->configReader->get($pluginConfig, 'dependencies', []);

        if (empty($dependencies)) {
            return [];
        }

        $missingDeps = [];
        foreach ($dependencies as $depPackage) {
            if (!$this->discoverer->isInstalled($depPackage)) {
                $missingDeps[] = $depPackage;
            }
        }

        return $missingDeps;
    }

    /**
     * 获取依赖指定插件的所有已安装插件
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
     * 获取依赖指定插件的所有已启用插件
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
     * 分发事件
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
     * 记录日志
     * 
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
    }

    /**
     * 获取插件发现器
     * 
     * @return PluginDiscovererInterface
     */
    public function getDiscoverer(): PluginDiscovererInterface
    {
        return $this->discoverer;
    }

    /**
     * 获取插件仓库
     * 
     * @return PluginRepositoryInterface
     */
    public function getRepository(): PluginRepositoryInterface
    {
        return $this->repository;
    }
}
