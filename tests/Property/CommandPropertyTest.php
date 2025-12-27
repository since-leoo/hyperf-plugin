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

namespace SinceLeoo\Plugin\Tests\Property;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SinceLeoo\Plugin\ConfigWriter;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\PluginManagerInterface;
use SinceLeoo\Plugin\MigrationRunner;
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;
use SinceLeoo\Plugin\PluginManager;
use SinceLeoo\Plugin\PluginRepository;
use SinceLeoo\Plugin\SeederRunner;

/**
 * Property-based tests for Plugin Commands.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify command argument parsing and output format properties.
 * Installation status is now tracked via install.lock files in plugin directories.
 * @internal
 * @coversNothing
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
        $this->migrationRunner = new MigrationRunner($this->discoverer);
        $this->seederRunner = new SeederRunner($this->discoverer);

        $this->manager = new PluginManager(
            $this->discoverer,
            $this->repository,
            $this->configWriter,
            $this->configReader,
            $this->migrationRunner,
            $this->seederRunner
        );

        $this->container = $this->createMockContainer();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * @dataProvider pluginListJsonOutputProvider
     */
    public function testPluginListJsonOutputContainsRequiredFields(array $pluginConfigs): void
    {
        foreach ($pluginConfigs as $config) {
            $this->createTestPlugin($config['name'], $config);
        }

        $localPlugins = $this->discoverer->discoverLocalPlugins();

        foreach ($localPlugins as $plugin) {
            $this->assertArrayHasKey('name', $plugin);
            $this->assertArrayHasKey('version', $plugin);
            $this->assertArrayHasKey('description', $plugin);
            $this->assertArrayHasKey('author', $plugin);
            $this->assertArrayHasKey('installed', $plugin);
            $this->assertArrayHasKey('enabled', $plugin);
        }
    }

    public static function pluginListJsonOutputProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $numPlugins = rand(1, 5);
            $pluginConfigs = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
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
     * @dataProvider pluginListStatusFilterProvider
     */
    public function testPluginListStatusFilterReturnsCorrectPlugins(array $pluginStates, string $filterStatus): void
    {
        foreach ($pluginStates as $packageName => $state) {
            $pluginDir = $this->createTestPlugin($packageName);

            if ($state['installed']) {
                $this->createInstallLock($pluginDir);
                $this->configWriter->setPluginEnabled($packageName, $state['enabled']);
            }
        }

        $localPlugins = $this->discoverer->discoverLocalPlugins();
        $installedPlugins = $this->discoverer->getInstalledPlugins();

        $allPlugins = [];
        foreach ($localPlugins as $plugin) {
            $name = $plugin['name'];
            $allPlugins[$name] = [
                'name' => $name,
                'installed' => $plugin['installed'],
                'enabled' => $plugin['enabled'],
            ];
        }

        $filtered = array_filter($allPlugins, function (array $plugin) use ($filterStatus): bool {
            return match ($filterStatus) {
                'installed' => $plugin['installed'],
                'enabled' => $plugin['installed'] && $plugin['enabled'],
                'disabled' => $plugin['installed'] && ! $plugin['enabled'],
                'available' => ! $plugin['installed'],
                default => true,
            };
        });

        $expectedCount = 0;
        foreach ($pluginStates as $state) {
            $matches = match ($filterStatus) {
                'installed' => $state['installed'],
                'enabled' => $state['installed'] && $state['enabled'],
                'disabled' => $state['installed'] && ! $state['enabled'],
                'available' => ! $state['installed'],
                default => true,
            };
            if ($matches) {
                ++$expectedCount;
            }
        }

        $this->assertCount($expectedCount, $filtered);

        foreach ($filtered as $name => $plugin) {
            $expectedState = $pluginStates[$name] ?? ['installed' => false, 'enabled' => false];

            switch ($filterStatus) {
                case 'installed':
                    $this->assertTrue($expectedState['installed']);
                    break;
                case 'enabled':
                    $this->assertTrue($expectedState['installed'] && $expectedState['enabled']);
                    break;
                case 'disabled':
                    $this->assertTrue($expectedState['installed'] && ! $expectedState['enabled']);
                    break;
                case 'available':
                    $this->assertFalse($expectedState['installed']);
                    break;
            }
        }
    }

    public static function pluginListStatusFilterProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        $statuses = ['installed', 'enabled', 'disabled', 'available'];

        for ($i = 0; $i < 100; ++$i) {
            $numPlugins = rand(2, 5);
            $pluginStates = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
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
     * @dataProvider enableCommandProvider
     */
    public function testEnableCommandSetsPluginStatusToEnabled(string $packageName): void
    {
        $this->createTestPlugin($packageName, ['enabled' => false]);
        $this->manager->install($packageName);

        $this->assertFalse($this->discoverer->isEnabled($packageName));

        $result = $this->manager->enable($packageName);

        $this->assertTrue($result);
        $this->assertTrue($this->discoverer->isEnabled($packageName));
    }

    public static function enableCommandProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }

        return $testCases;
    }

    /**
     * @dataProvider disableCommandProvider
     */
    public function testDisableCommandSetsPluginStatusToDisabled(string $packageName): void
    {
        $this->createTestPlugin($packageName, ['enabled' => true]);
        $this->manager->install($packageName);

        $this->assertTrue($this->discoverer->isEnabled($packageName));

        $result = $this->manager->disable($packageName);

        $this->assertTrue($result);
        $this->assertFalse($this->discoverer->isEnabled($packageName));
    }

    public static function disableCommandProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }

        return $testCases;
    }

    /**
     * @dataProvider enableNonInstalledPluginProvider
     */
    public function testEnableNonInstalledPluginReturnsError(string $packageName): void
    {
        $result = $this->manager->enable($packageName);
        $this->assertFalse($result);
    }

    public static function enableNonInstalledPluginProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-nonexistent-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }

        return $testCases;
    }

    /**
     * @dataProvider disableNonInstalledPluginProvider
     */
    public function testDisableNonInstalledPluginReturnsError(string $packageName): void
    {
        $result = $this->manager->disable($packageName);
        $this->assertFalse($result);
    }

    public static function disableNonInstalledPluginProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-nonexistent-' . $i;
            $testCases["iteration_{$i}"] = [$packageName];
        }

        return $testCases;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

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

    private function createTestPlugin(string $name, array $config = []): string
    {
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

    private function createInstallLock(string $pluginDir, array $lockData = []): void
    {
        $defaultLockData = [
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
            'migrations_executed' => [],
            'seeder_executed' => false,
        ];

        file_put_contents(
            $pluginDir . '/install.lock',
            json_encode(array_merge($defaultLockData, $lockData), JSON_PRETTY_PRINT)
        );
    }

    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 1000);
    }
}
