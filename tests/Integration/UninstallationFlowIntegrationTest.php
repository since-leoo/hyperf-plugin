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

namespace SinceLeoo\Plugin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\ConfigWriter;
use SinceLeoo\Plugin\MigrationRunner;
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;
use SinceLeoo\Plugin\PluginManager;
use SinceLeoo\Plugin\PluginRepository;
use SinceLeoo\Plugin\SeederRunner;

/**
 * Integration tests for plugin uninstallation flow.
 *
 * Feature: hyperf-plugin-refactor
 *
 * Tests uninstallation with migration rollback and database preservation.
 *
 * **Validates: Requirements 15.2, 15.3**
 * @internal
 * @coversNothing
 */
class UninstallationFlowIntegrationTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . '/plugin_uninstall_integration_test_' . uniqid();
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
     * Test uninstallation with rollback_on_uninstall = true rolls back migrations.
     *
     * **Validates: Requirements 15.2**
     */
    public function testUninstallationWithRollbackTrue(): void
    {
        $packageName = 'vendor/rollback-plugin';
        $trackingFile = $this->tempDir . '/rollback_tracking.txt';

        // Create plugin with migrations and rollback_on_uninstall = true
        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => true,
        ], true);

        // Create migration files
        $this->createMigrationFile($pluginDir, '2024_01_01_000001_create_table_a.php', $trackingFile);
        $this->createMigrationFile($pluginDir, '2024_01_02_000002_create_table_b.php', $trackingFile);

        // Install the plugin
        $this->manager->install($packageName);
        $this->assertTrue($this->discoverer->isInstalled($packageName));

        // Clear tracking file to track only rollback
        file_put_contents($trackingFile, '');

        // Uninstall the plugin
        $result = $this->manager->uninstall($packageName);

        $this->assertTrue($result, 'Uninstallation should succeed');
        $this->assertFalse($this->discoverer->isInstalled($packageName), 'Plugin should be uninstalled');

        // Verify migrations were rolled back (down methods called)
        $trackingContent = file_get_contents($trackingFile);
        $this->assertStringContainsString('down:2024_01_01_000001_create_table_a.php', $trackingContent);
        $this->assertStringContainsString('down:2024_01_02_000002_create_table_b.php', $trackingContent);
    }

    /**
     * Test uninstallation with rollback_on_uninstall = false preserves database.
     *
     * **Validates: Requirements 15.3**
     */
    public function testUninstallationWithRollbackFalsePreservesDatabase(): void
    {
        $packageName = 'vendor/preserve-plugin';
        $trackingFile = $this->tempDir . '/preserve_tracking.txt';

        // Create plugin with migrations and rollback_on_uninstall = false (default)
        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => false,
        ], true);

        // Create migration files
        $this->createMigrationFile($pluginDir, '2024_01_01_000001_create_table.php', $trackingFile);

        // Install the plugin
        $this->manager->install($packageName);
        $this->assertTrue($this->discoverer->isInstalled($packageName));

        // Clear tracking file to track only uninstall actions
        file_put_contents($trackingFile, '');

        // Uninstall the plugin
        $result = $this->manager->uninstall($packageName);

        $this->assertTrue($result, 'Uninstallation should succeed');
        $this->assertFalse($this->discoverer->isInstalled($packageName), 'Plugin should be uninstalled');

        // Verify migrations were NOT rolled back (no down methods called)
        $trackingContent = file_get_contents($trackingFile);
        $this->assertStringNotContainsString('down:', $trackingContent, 'Migrations should not be rolled back');
    }

    /**
     * Test uninstallation with --rollback flag overrides plugin.json.
     *
     * **Validates: Requirements 15.2**
     */
    public function testUninstallationWithRollbackFlagOverride(): void
    {
        $packageName = 'vendor/override-plugin';
        $trackingFile = $this->tempDir . '/override_tracking.txt';

        // Create plugin with rollback_on_uninstall = false
        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => false,
        ], true);

        $this->createMigrationFile($pluginDir, '2024_01_01_000001_create_table.php', $trackingFile);

        // Install the plugin
        $this->manager->install($packageName);

        // Clear tracking file
        file_put_contents($trackingFile, '');

        // Uninstall with rollback = true (override)
        $result = $this->manager->uninstall($packageName, false, true);

        $this->assertTrue($result);

        // Verify migrations WERE rolled back despite plugin.json setting
        $trackingContent = file_get_contents($trackingFile);
        $this->assertStringContainsString('down:2024_01_01_000001_create_table.php', $trackingContent);
    }

    /**
     * Test uninstallation with --no-rollback flag overrides plugin.json.
     *
     * **Validates: Requirements 15.3**
     */
    public function testUninstallationWithNoRollbackFlagOverride(): void
    {
        $packageName = 'vendor/no-rollback-plugin';
        $trackingFile = $this->tempDir . '/no_rollback_tracking.txt';

        // Create plugin with rollback_on_uninstall = true
        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => true,
        ], true);

        $this->createMigrationFile($pluginDir, '2024_01_01_000001_create_table.php', $trackingFile);

        // Install the plugin
        $this->manager->install($packageName);

        // Clear tracking file
        file_put_contents($trackingFile, '');

        // Uninstall with rollback = false (override)
        $result = $this->manager->uninstall($packageName, false, false);

        $this->assertTrue($result);

        // Verify migrations were NOT rolled back despite plugin.json setting
        $trackingContent = file_get_contents($trackingFile);
        $this->assertStringNotContainsString('down:', $trackingContent);
    }

    /**
     * Test uninstallation rolls back migrations in reverse order.
     *
     * **Validates: Requirements 15.2**
     */
    public function testUninstallationRollsBackInReverseOrder(): void
    {
        $packageName = 'vendor/order-plugin';
        $trackingFile = $this->tempDir . '/order_tracking.txt';

        // Create plugin with migrations
        $pluginDir = $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => true,
        ], true);

        // Create migration files
        $this->createMigrationFile($pluginDir, '2024_01_01_000001_first.php', $trackingFile);
        $this->createMigrationFile($pluginDir, '2024_01_02_000002_second.php', $trackingFile);
        $this->createMigrationFile($pluginDir, '2024_01_03_000003_third.php', $trackingFile);

        // Install the plugin
        $this->manager->install($packageName);

        // Clear tracking file
        file_put_contents($trackingFile, '');

        // Uninstall the plugin
        $this->manager->uninstall($packageName);

        // Verify rollback order (should be descending by filename)
        $trackingContent = file_get_contents($trackingFile);
        $lines = array_filter(explode("\n", trim($trackingContent)));

        $this->assertCount(3, $lines);
        $this->assertEquals('down:2024_01_03_000003_third.php', $lines[0]);
        $this->assertEquals('down:2024_01_02_000002_second.php', $lines[1]);
        $this->assertEquals('down:2024_01_01_000001_first.php', $lines[2]);
    }

    /**
     * Test uninstallation of non-installed plugin fails.
     */
    public function testUninstallationOfNonInstalledPluginFails(): void
    {
        $packageName = 'vendor/non-existent-plugin';

        $result = $this->manager->uninstall($packageName);

        $this->assertFalse($result, 'Uninstallation of non-installed plugin should fail');
    }

    /**
     * Test uninstallation removes plugin from configuration.
     */
    public function testUninstallationRemovesPluginFromConfig(): void
    {
        $packageName = 'vendor/config-plugin';

        // Create and install plugin
        $this->createTestPlugin($packageName);
        $this->manager->install($packageName);

        // Verify plugin is in config
        $config = $this->configWriter->getConfig();
        $this->assertArrayHasKey($packageName, $config['installed']);

        // Uninstall
        $this->manager->uninstall($packageName);

        // Verify plugin is removed from config
        $config = $this->configWriter->getConfig();
        $this->assertArrayNotHasKey($packageName, $config['installed'] ?? []);
    }

    /**
     * Test uninstallation removes enabled status.
     */
    public function testUninstallationRemovesEnabledStatus(): void
    {
        $packageName = 'vendor/enabled-plugin';

        // Create and install plugin with enabled = true
        $this->createTestPlugin($packageName, ['enabled' => true]);
        $this->manager->install($packageName);

        // Verify plugin is enabled
        $this->assertTrue($this->discoverer->isEnabled($packageName));

        // Uninstall
        $this->manager->uninstall($packageName);

        // Verify enabled status is removed
        $this->assertFalse($this->discoverer->isEnabled($packageName));
    }

    /**
     * Test uninstallation blocked when other plugins depend on it.
     *
     * **Validates: Requirements 15.2, 15.3**
     */
    public function testUninstallationBlockedWithDependents(): void
    {
        $basePluginName = 'vendor/base-plugin';
        $dependentPluginName = 'vendor/dependent-plugin';

        // Create and install base plugin
        $this->createTestPlugin($basePluginName);
        $this->manager->install($basePluginName);

        // Create and install dependent plugin
        $this->createTestPlugin($dependentPluginName, [
            'dependencies' => [$basePluginName],
        ]);
        $this->manager->install($dependentPluginName);

        // Try to uninstall base plugin (should fail)
        $result = $this->manager->uninstall($basePluginName);

        $this->assertFalse($result, 'Uninstallation should be blocked when other plugins depend on it');
        $this->assertTrue($this->discoverer->isInstalled($basePluginName), 'Base plugin should still be installed');
    }

    /**
     * Test force uninstallation bypasses dependency check.
     */
    public function testForceUninstallationBypassesDependencyCheck(): void
    {
        $basePluginName = 'vendor/force-base-plugin';
        $dependentPluginName = 'vendor/force-dependent-plugin';

        // Create and install base plugin
        $this->createTestPlugin($basePluginName);
        $this->manager->install($basePluginName);

        // Create and install dependent plugin
        $this->createTestPlugin($dependentPluginName, [
            'dependencies' => [$basePluginName],
        ]);
        $this->manager->install($dependentPluginName);

        // Force uninstall base plugin
        $result = $this->manager->uninstall($basePluginName, true);

        $this->assertTrue($result, 'Force uninstallation should succeed');
        $this->assertFalse($this->discoverer->isInstalled($basePluginName));
    }

    /**
     * Test uninstallation without migrations.
     */
    public function testUninstallationWithoutMigrations(): void
    {
        $packageName = 'vendor/no-migration-plugin';

        // Create plugin without migrations
        $this->createTestPlugin($packageName, [
            'rollback_on_uninstall' => true,
        ]);

        // Install
        $this->manager->install($packageName);
        $this->assertTrue($this->discoverer->isInstalled($packageName));

        // Uninstall (should succeed even with rollback_on_uninstall = true)
        $result = $this->manager->uninstall($packageName);

        $this->assertTrue($result);
        $this->assertFalse($this->discoverer->isInstalled($packageName));
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
     * Create a test plugin directory with plugin.json and optional migrations.
     */
    private function createTestPlugin(
        string $name,
        array $config = [],
        bool $withMigrations = false
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

        return $pluginDir;
    }

    /**
     * Create a migration file with tracking capability.
     */
    private function createMigrationFile(string $pluginDir, string $filename, string $trackingFile): void
    {
        $migrationPath = $pluginDir . '/Database/Migrations/' . $filename;

        $content = <<<PHP
<?php
return new class {
    public function up(): void
    {
        \$content = file_exists('{$trackingFile}') ? file_get_contents('{$trackingFile}') : '';
        file_put_contents('{$trackingFile}', \$content . "up:{$filename}\n");
    }
    
    public function down(): void
    {
        \$content = file_exists('{$trackingFile}') ? file_get_contents('{$trackingFile}') : '';
        file_put_contents('{$trackingFile}', \$content . "down:{$filename}\n");
    }
};
PHP;

        file_put_contents($migrationPath, $content);
    }
}
