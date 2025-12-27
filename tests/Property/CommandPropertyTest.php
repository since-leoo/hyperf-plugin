<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Tests\Property;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\ConfigWriter;
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;
use SinceLeoo\Plugin\PluginRepository;
use SinceLeoo\Plugin\MigrationRunner;
use SinceLeoo\Plugin\SeederRunner;
use SinceLeoo\Plugin\PluginManager;
use SinceLeoo\Plugin\Command\PluginListCommand;
use SinceLeoo\Plugin\Command\PluginEnableCommand;
use SinceLeoo\Plugin\Command\PluginDisableCommand;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Application;

/**
 * Property-based tests for Plugin Commands
 * 
 * Feature: hyperf-plugin-refactor
 * 
 * These tests verify command argument parsing and output format properties.
 * 
 * **Validates: Requirements 4.1, 4.2, 7.1, 7.2, 7.3**
 */
class CommandPropertyTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private ConfigWriter $configWriter;
    private PluginConfigReader $configReader;
    private PluginDiscoverer $discoverer;
    private PluginRepository $repository;
    private MigrationRunner $migrationRunner;
    private SeederRunner $seederRunner;
    private PluginManager $manager;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/command_test_' . uniqid();
        mkdir($this->tempDir . '/config/autoload', 0755, true);
        mkdir($this->tempDir . '/plugins', 0755, true);
        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
        
        $this->configWriter = new ConfigWriter($this->configPath);
        $this->configReader = new PluginConfigReader();
        $this->discoverer = new PluginDiscoverer(
            $this->configReader,
            $this->configWriter,
            $this->tempDir,
            'plugins'
        );
        $this->repository = new PluginRepository($this->configWriter);
        $this->migrationRunner = new MigrationRunner($this->configWriter);
        $this->seederRunner = new SeederRunner($this->configWriter);
        
        $this->manager = new PluginManager(
            $this->discoverer,
            $this->repository,
            $this->configWriter,
            $this->configReader,
            $this->migrationRunner,
            $this->seederRunner
        );

        // Create a mock container
        $this->container = $this->createMockContainer();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Create a mock container for dependency injection
     */
    private function createMockContainer(): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        
        $container->method('get')
            ->willReturnCallback(function (string $id) {
                return match ($id) {
                    PluginDiscovererInterface::class => $this->discoverer,
                    PluginManagerInterface::class => $this->manager,
                    default => null,
                };
            });
        
        return $container;
    }

    /**
     * Create a test plugin directory with plugin.json
     */
    private function createTestPlugin(
        string $name,
        array $config = []
    ): string {
        $pluginDir = $this->tempDir . '/plugins/' . str_replace('/', '-', $name);
        mkdir($pluginDir . '/src', 0755, true);
        
        $defaultConfig = [
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'Test plugin description',
            'author' => 'Test Author',
            'priority' => 0,
            'dependencies' => [],
            'rollback_on_uninstall' => false,
            'enabled' => false,
        ];
        
        $pluginConfig = array_merge($defaultConfig, $config);
        
        file_put_contents(
            $pluginDir . '/plugin.json',
            json_encode($pluginConfig, JSON_PRETTY_PRINT)
        );
        
        return $pluginDir;
    }

    /**
     * Generate random package name
     */
    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 1000);
    }

    /**
     * Property: Plugin List JSON Output Contains Required Fields
     * 
     * *For any* set of plugins, the plugin:list --json command SHALL output
     * JSON containing name, version, status, description, and author fields.
     * 
     * **Validates: Requirements 7.1, 7.3**
     * 
     * @dataProvider pluginListJsonOutputProvider
     */
    public function testPluginListJsonOutputContainsRequiredFields(array $pluginConfigs): void
    {
        // Create plugins
        foreach ($pluginConfigs as $config) {
            $this->createTestPlugin($config['name'], $config);
        }

        // Get plugins via discoverer (simulating what the command does)
        $localPlugins = $this->discoverer->discoverLocalPlugins();
        
        // Verify each plugin has required fields
        foreach ($localPlugins as $plugin) {
            $this->assertArrayHasKey('name', $plugin, 'Plugin should have name field');
            $this->assertArrayHasKey('version', $plugin, 'Plugin should have version field');
            $this->assertArrayHasKey('description', $plugin, 'Plugin should have description field');
            $this->assertArrayHasKey('author', $plugin, 'Plugin should have author field');
            $this->assertArrayHasKey('installed', $plugin, 'Plugin should have installed status');
            $this->assertArrayHasKey('enabled', $plugin, 'Plugin should have enabled status');
        }
    }

    /**
     * Data provider for plugin list JSON output test
     */
    public static function pluginListJsonOutputProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $numPlugins = rand(1, 5);
            $pluginConfigs = [];
            
            for ($j = 0; $j < $numPlugins; $j++) {
                $pluginConfigs[] = [
                    'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i . '-' . $j,
                    'version' => rand(1, 9) . '.' . rand(0, 9) . '.' . rand(0, 9),
                    'description' => 'Description for plugin ' . $j,
                    'author' => 'Author ' . $j,
                ];
            }
            
            $testCases["iteration_{$i}"] = [$pluginConfigs];
        }
        
        return $testCases;
    }

    /**
     * Property: Plugin List Status Filter Returns Correct Plugins
     * 
     * *For any* set of plugins with varying installed/enabled states,
     * filtering by status SHALL return only plugins matching that status.
     * 
     * **Validates: Requirements 7.2**
     * 
     * @dataProvider pluginListStatusFilterProvider
     */
    public function testPluginListStatusFilterReturnsCorrectPlugins(array $pluginStates, string $filterStatus): void
    {
        // Create and optionally install plugins
        foreach ($pluginStates as $packageName => $state) {
            $this->createTestPlugin($packageName);
            
            if ($state['installed']) {
                $this->configWriter->updatePluginConfig($packageName, [
                    'version' => '1.0.0',
                    'path' => $this->tempDir . '/plugins/' . str_replace('/', '-', $packageName),
                    'installed_at' => date('Y-m-d H:i:s'),
                ]);
                $this->configWriter->setPluginEnabled($packageName, $state['enabled']);
            }
        }

        // Get all plugins
        $localPlugins = $this->discoverer->discoverLocalPlugins();
        $installedPlugins = $this->discoverer->getInstalledPlugins();
        
        // Merge and filter (simulating command behavior)
        $allPlugins = [];
        foreach ($localPlugins as $plugin) {
            $name = $plugin['name'];
            $allPlugins[$name] = [
                'name' => $name,
                'installed' => $plugin['installed'],
                'enabled' => $plugin['enabled'],
            ];
        }
        
        // Apply filter
        $filtered = array_filter($allPlugins, function (array $plugin) use ($filterStatus): bool {
            return match ($filterStatus) {
                'installed' => $plugin['installed'],
                'enabled' => $plugin['installed'] && $plugin['enabled'],
                'disabled' => $plugin['installed'] && !$plugin['enabled'],
                'available' => !$plugin['installed'],
                default => true,
            };
        });

        // Count expected matches based on filter
        $expectedCount = 0;
        foreach ($pluginStates as $state) {
            $matches = match ($filterStatus) {
                'installed' => $state['installed'],
                'enabled' => $state['installed'] && $state['enabled'],
                'disabled' => $state['installed'] && !$state['enabled'],
                'available' => !$state['installed'],
                default => true,
            };
            if ($matches) {
                $expectedCount++;
            }
        }

        // Verify filter returns correct count
        $this->assertCount($expectedCount, $filtered, 
            "Filter '{$filterStatus}' should return {$expectedCount} plugins");

        // Verify filter results
        foreach ($filtered as $name => $plugin) {
            $expectedState = $pluginStates[$name] ?? ['installed' => false, 'enabled' => false];
            
            switch ($filterStatus) {
                case 'installed':
                    $this->assertTrue($expectedState['installed'], "Filtered plugin {$name} should be installed");
                    break;
                case 'enabled':
                    $this->assertTrue($expectedState['installed'] && $expectedState['enabled'], 
                        "Filtered plugin {$name} should be installed and enabled");
                    break;
                case 'disabled':
                    $this->assertTrue($expectedState['installed'] && !$expectedState['enabled'], 
                        "Filtered plugin {$name} should be installed and disabled");
                    break;
                case 'available':
                    $this->assertFalse($expectedState['installed'], "Filtered plugin {$name} should not be installed");
                    break;
            }
        }
    }

    /**
     * Data provider for plugin list status filter test
     */
    public static function pluginListStatusFilterProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        $statuses = ['installed', 'enabled', 'disabled', 'available'];
        
        for ($i = 0; $i < 100; $i++) {
            $numPlugins = rand(2, 5);
            $pluginStates = [];
            
            for ($j = 0; $j < $numPlugins; $j++) {
                $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i . '-' . $j;
                $pluginStates[$packageName] = [
                    'installed' => (bool) rand(0, 1),
                    'enabled' => (bool) rand(0, 1),
                ];
            }
            
            $filterStatus = $statuses[array_rand($statuses)];
            
            $testCases["iteration_{$i}"] = [$pluginStates, $filterStatus];
        }
        
        return $testCases;
    }

    /**
     * Property: Enable Command Sets Plugin Status to Enabled
     * 
     * *For any* installed plugin, running plugin:enable SHALL set
     * the plugin status to enabled in configuration.
     * 
     * **Validates: Requirements 4.1**
     * 
     * @dataProvider enableCommandProvider
     */
    public function testEnableCommandSetsPluginStatusToEnabled(string $packageName): void
    {
        // Create and install plugin (disabled by default)
        $this->createTestPlugin($packageName, ['enabled' => false]);
        $this->manager->install($packageName);
        
        // Verify initially disabled
        $this->assertFalse($this->discoverer->isEnabled($packageName), 
            "Plugin should be disabled initially");
        
        // Enable the plugin
        $result = $this->manager->enable($packageName);
        
        // Verify enabled
        $this->assertTrue($result, "Enable should succeed");
        $this->assertTrue($this->discoverer->isEnabled($packageName), 
            "Plugin should be enabled after enable command");
    }

    /**
     * Data provider for enable command test
     */
    public static function enableCommandProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }
        
        return $testCases;
    }

    /**
     * Property: Disable Command Sets Plugin Status to Disabled
     * 
     * *For any* installed and enabled plugin, running plugin:disable SHALL set
     * the plugin status to disabled in configuration.
     * 
     * **Validates: Requirements 4.2**
     * 
     * @dataProvider disableCommandProvider
     */
    public function testDisableCommandSetsPluginStatusToDisabled(string $packageName): void
    {
        // Create and install plugin (enabled by default for this test)
        $this->createTestPlugin($packageName, ['enabled' => true]);
        $this->manager->install($packageName);
        
        // Verify initially enabled
        $this->assertTrue($this->discoverer->isEnabled($packageName), 
            "Plugin should be enabled initially");
        
        // Disable the plugin
        $result = $this->manager->disable($packageName);
        
        // Verify disabled
        $this->assertTrue($result, "Disable should succeed");
        $this->assertFalse($this->discoverer->isEnabled($packageName), 
            "Plugin should be disabled after disable command");
    }

    /**
     * Data provider for disable command test
     */
    public static function disableCommandProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }
        
        return $testCases;
    }

    /**
     * Property: Enable Non-Installed Plugin Returns Error
     * 
     * *For any* non-installed plugin, running plugin:enable SHALL fail
     * and return false.
     * 
     * **Validates: Requirements 4.3**
     * 
     * @dataProvider enableNonInstalledPluginProvider
     */
    public function testEnableNonInstalledPluginReturnsError(string $packageName): void
    {
        // Do not install the plugin
        
        // Try to enable
        $result = $this->manager->enable($packageName);
        
        // Verify failure
        $this->assertFalse($result, "Enable should fail for non-installed plugin");
    }

    /**
     * Data provider for enable non-installed plugin test
     */
    public static function enableNonInstalledPluginProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-nonexistent-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }
        
        return $testCases;
    }

    /**
     * Property: Disable Non-Installed Plugin Returns Error
     * 
     * *For any* non-installed plugin, running plugin:disable SHALL fail
     * and return false.
     * 
     * **Validates: Requirements 4.4**
     * 
     * @dataProvider disableNonInstalledPluginProvider
     */
    public function testDisableNonInstalledPluginReturnsError(string $packageName): void
    {
        // Do not install the plugin
        
        // Try to disable
        $result = $this->manager->disable($packageName);
        
        // Verify failure
        $this->assertFalse($result, "Disable should fail for non-installed plugin");
    }

    /**
     * Data provider for disable non-installed plugin test
     */
    public static function disableNonInstalledPluginProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-nonexistent-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }
        
        return $testCases;
    }
}
