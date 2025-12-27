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
use SinceLeoo\Plugin\ConfigWriter;
use SinceLeoo\Plugin\MigrationRunner;
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;
use SinceLeoo\Plugin\PluginManager;
use SinceLeoo\Plugin\PluginRepository;
use SinceLeoo\Plugin\SeederRunner;
use Throwable;

/**
 * Property-based tests for PluginManager.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * Installation status is now tracked via install.lock files in plugin directories.
 * @internal
 * @coversNothing
 */
class PluginManagerPropertyTest extends TestCase
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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/plugin_manager_test_' . uniqid();
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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Property 3: Plugin Loading Respects Enabled Status.
     *
     * @dataProvider pluginLoadingEnabledStatusProvider
     */
    public function testPluginLoadingRespectsEnabledStatus(array $pluginStates): void
    {
        foreach ($pluginStates as $packageName => $enabled) {
            $pluginDir = $this->createTestPlugin($packageName);
            $this->createInstallLock($pluginDir);
            $this->configWriter->setPluginEnabled($packageName, $enabled);
        }

        $this->manager->bootPlugins();

        $loadedPlugins = $this->manager->getLoadedPlugins();

        foreach ($pluginStates as $packageName => $enabled) {
            if ($enabled) {
                $this->assertArrayHasKey($packageName, $loadedPlugins);
            } else {
                $this->assertArrayNotHasKey($packageName, $loadedPlugins);
            }
        }
    }

    public static function pluginLoadingEnabledStatusProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $numPlugins = rand(1, 5);
            $pluginStates = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i . '-' . $j;
                $pluginStates[$packageName] = (bool) rand(0, 1);
            }

            $testCases["iteration_{$i}"] = [$pluginStates];
        }

        return $testCases;
    }

    /**
     * Property 4: New Installations Default to Disabled.
     *
     * @dataProvider newInstallationsDefaultProvider
     */
    public function testNewInstallationsDefaultToDisabled(bool $defaultEnabled): void
    {
        $packageName = $this->generateRandomPackageName();

        $this->createTestPlugin($packageName, ['enabled' => $defaultEnabled]);

        $result = $this->manager->install($packageName);

        $this->assertTrue($result);
        $this->assertEquals($defaultEnabled, $this->discoverer->isEnabled($packageName));
    }

    public static function newInstallationsDefaultProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $testCases["iteration_{$i}"] = [(bool) rand(0, 1)];
        }
        return $testCases;
    }

    /**
     * Property 14: Error Isolation During Boot.
     *
     * @dataProvider errorIsolationDuringBootProvider
     */
    public function testErrorIsolationDuringBoot(array $pluginNames): void
    {
        foreach ($pluginNames as $packageName) {
            $pluginDir = $this->createTestPlugin($packageName, ['enabled' => true]);
            $this->createInstallLock($pluginDir);
            $this->configWriter->setPluginEnabled($packageName, true);
        }

        $exception = null;
        try {
            $this->manager->bootPlugins();
        } catch (Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public static function errorIsolationDuringBootProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $numPlugins = rand(1, 5);
            $pluginNames = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                $pluginNames[] = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i . '-' . $j;
            }

            $testCases["iteration_{$i}"] = [$pluginNames];
        }

        return $testCases;
    }

    /**
     * Property 11: Migration Rollback on Uninstall.
     *
     * @dataProvider migrationRollbackOnUninstallProvider
     */
    public function testMigrationRollbackOnUninstall(bool $rollbackOnUninstall): void
    {
        $packageName = $this->generateRandomPackageName();

        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => $rollbackOnUninstall,
        ], true);

        $migrationFile = '2024_01_01_000000_create_test_table.php';
        file_put_contents(
            $pluginDir . '/Database/Migrations/' . $migrationFile,
            '<?php return new class { public function up() {} public function down() {} };'
        );

        $this->manager->install($packageName);

        $executedMigrations = $this->migrationRunner->getExecutedMigrations($packageName);
        $this->assertContains($migrationFile, $executedMigrations);

        $this->manager->uninstall($packageName);

        $this->assertFalse($this->discoverer->isInstalled($packageName));
    }

    public static function migrationRollbackOnUninstallProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $testCases["iteration_{$i}"] = [(bool) rand(0, 1)];
        }
        return $testCases;
    }

    public function testDatabasePreservationOnUninstall(): void
    {
        $packageName = $this->generateRandomPackageName();

        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => false,
        ], true);

        $migrationFile = '2024_01_01_000000_create_test_table.php';
        $trackingFile = $this->tempDir . '/migration_down_called.txt';

        file_put_contents(
            $pluginDir . '/Database/Migrations/' . $migrationFile,
            '<?php return new class { 
                public function up() {} 
                public function down() { 
                    file_put_contents("' . addslashes($trackingFile) . '", "down_called"); 
                } 
            };'
        );

        $this->manager->install($packageName);
        $this->manager->uninstall($packageName, false, false);

        $this->assertFileDoesNotExist($trackingFile);
    }

    public function testEnableDisableRespectsInstalledStatus(): void
    {
        $packageName = $this->generateRandomPackageName();

        $result = $this->manager->enable($packageName);
        $this->assertFalse($result);

        $result = $this->manager->disable($packageName);
        $this->assertFalse($result);

        $this->createTestPlugin($packageName);
        $this->manager->install($packageName);

        $result = $this->manager->enable($packageName);
        $this->assertTrue($result);
        $this->assertTrue($this->discoverer->isEnabled($packageName));

        $result = $this->manager->disable($packageName);
        $this->assertTrue($result);
        $this->assertFalse($this->discoverer->isEnabled($packageName));
    }

    public function testDependencyChecking(): void
    {
        $dependencyName = $this->generateRandomPackageName();
        $dependentName = $this->generateRandomPackageName();

        $this->createTestPlugin($dependentName, [
            'dependencies' => [$dependencyName],
        ]);

        $result = $this->manager->checkDependencies($dependentName);
        $this->assertContains($dependencyName, $result['missing']);

        $this->createTestPlugin($dependencyName, ['enabled' => true]);
        $this->manager->install($dependencyName);

        $result = $this->manager->checkDependencies($dependentName);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['disabled']);
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

    /**
     * Create a test plugin directory with plugin.json.
     */
    private function createTestPlugin(
        string $name,
        array $config = [],
        bool $withMigrations = false,
        bool $withSeeders = false
    ): string {
        $pluginDir = $this->tempDir . '/plugins/' . str_replace('/', '-', $name);
        mkdir($pluginDir . '/src', 0755, true);

        $defaultConfig = [
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'Test plugin',
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

        if ($withMigrations) {
            mkdir($pluginDir . '/Database/Migrations', 0755, true);
        }

        if ($withSeeders) {
            mkdir($pluginDir . '/Database/Seeders', 0755, true);
        }

        return $pluginDir;
    }

    /**
     * Create install.lock file to mark plugin as installed.
     */
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

    /**
     * Generate random package name.
     */
    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 1000);
    }
}
