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

/**
 * Property-based tests for MigrationRunner.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * Migration status is now stored in install.lock files in plugin directories.
 * @internal
 * @coversNothing
 */
class MigrationRunnerPropertyTest extends TestCase
{
    private string $tempDir;

    private string $configPath;

    private ConfigWriter $configWriter;

    private PluginConfigReader $configReader;

    private PluginDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/migration_runner_test_' . uniqid();
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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Property 6: Migration Execution on Install.
     *
     * @dataProvider migrationExecutionOnInstallProvider
     */
    public function testMigrationExecutionOnInstall(array $migrationFilenames): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new MigrationRunner($this->discoverer);

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($migrationPath, $filename, $trackingFile);
        }

        $this->assertEmpty($runner->getExecutedMigrations($packageName));

        $executedMigrations = $runner->migrate($packageName, $migrationPath);

        $this->assertCount(count($migrationFilenames), $executedMigrations);

        $trackedMigrations = $runner->getExecutedMigrations($packageName);
        $this->assertCount(count($migrationFilenames), $trackedMigrations);

        foreach ($migrationFilenames as $filename) {
            $this->assertContains($filename, $executedMigrations);
            $this->assertContains($filename, $trackedMigrations);
        }

        $pendingMigrations = $runner->getPendingMigrations($packageName, $migrationPath);
        $this->assertEmpty($pendingMigrations);
    }

    public static function migrationExecutionOnInstallProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $numMigrations = rand(1, 5);
            $filenames = [];
            $baseTimestamp = strtotime('2024-01-01');
            for ($j = 0; $j < $numMigrations; ++$j) {
                $timestamp = date('Y_m_d_His', $baseTimestamp + ($j * 86400) + rand(0, 86399));
                $tableName = 'table_' . chr(ord('a') + rand(0, 25)) . rand(1, 100);
                $filenames[] = $timestamp . '_create_' . $tableName . '.php';
            }
            $testCases["iteration_{$i}"] = [$filenames];
        }
        return $testCases;
    }

    /**
     * Property 7: Migration Execution Order.
     *
     * @dataProvider migrationExecutionOrderProvider
     */
    public function testMigrationExecutionOrder(array $migrationFilenames): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new MigrationRunner($this->discoverer);

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($migrationPath, $filename, $trackingFile);
        }

        $runner->migrate($packageName, $migrationPath);

        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';
        $executionLog = array_filter(explode("\n", trim($trackingContent)));

        $executedOrder = array_map(fn ($line) => str_replace('up:', '', $line), $executionLog);

        $expectedOrder = $migrationFilenames;
        sort($expectedOrder, SORT_STRING);

        $this->assertEquals($expectedOrder, $executedOrder);
    }

    public static function migrationExecutionOrderProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $numMigrations = rand(2, 6);
            $filenames = [];
            $baseTimestamp = strtotime('2024-01-01');
            for ($j = 0; $j < $numMigrations; ++$j) {
                $randomDays = rand(0, 365);
                $randomSeconds = rand(0, 86399);
                $timestamp = date('Y_m_d_His', $baseTimestamp + ($randomDays * 86400) + $randomSeconds);
                $tableName = 'table_' . chr(ord('a') + rand(0, 25)) . rand(1, 100);
                $filenames[] = $timestamp . '_create_' . $tableName . '.php';
            }
            $filenames = array_unique($filenames);
            if (count($filenames) >= 2) {
                $testCases["iteration_{$i}"] = [$filenames];
            }
        }
        return $testCases;
    }

    public function testRollbackExecutesInReverseOrder(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new MigrationRunner($this->discoverer);

        $migrationFilenames = [
            '2024_01_01_000001_create_first_table.php',
            '2024_01_02_000002_create_second_table.php',
            '2024_01_03_000003_create_third_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($migrationPath, $filename, $trackingFile);
        }

        $runner->migrate($packageName, $migrationPath);
        file_put_contents($trackingFile, '');

        $rolledBackMigrations = $runner->rollback($packageName, $migrationPath);

        $trackingContent = file_get_contents($trackingFile);
        $rollbackLog = array_filter(explode("\n", trim($trackingContent)));
        $rollbackOrder = array_map(fn ($line) => str_replace('down:', '', $line), $rollbackLog);

        $expectedOrder = $migrationFilenames;
        rsort($expectedOrder, SORT_STRING);

        $this->assertEquals($expectedOrder, $rollbackOrder);
        $this->assertCount(count($migrationFilenames), $rolledBackMigrations);
        $this->assertEmpty($runner->getExecutedMigrations($packageName));
    }

    public function testMigrateIsIdempotent(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new MigrationRunner($this->discoverer);

        $migrationFilenames = [
            '2024_01_01_000001_create_test_table.php',
            '2024_01_02_000002_create_another_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($migrationPath, $filename, $trackingFile);
        }

        $firstRun = $runner->migrate($packageName, $migrationPath);
        $this->assertCount(2, $firstRun);

        $secondRun = $runner->migrate($packageName, $migrationPath);
        $this->assertEmpty($secondRun);

        $trackingContent = file_get_contents($trackingFile);
        $executionCount = substr_count($trackingContent, 'up:');
        $this->assertEquals(2, $executionCount);
    }

    public function testGetPendingMigrationsReturnsCorrectList(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new MigrationRunner($this->discoverer);

        $migrationFilenames = [
            '2024_01_01_000001_create_first_table.php',
            '2024_01_02_000002_create_second_table.php',
            '2024_01_03_000003_create_third_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($migrationPath, $filename, $trackingFile);
        }

        $pending = $runner->getPendingMigrations($packageName, $migrationPath);
        $this->assertCount(3, $pending);

        $runner->migrate($packageName, $migrationPath);

        $pending = $runner->getPendingMigrations($packageName, $migrationPath);
        $this->assertEmpty($pending);

        $this->createMigrationFile($migrationPath, '2024_01_04_000004_create_fourth_table.php', $trackingFile);

        $pending = $runner->getPendingMigrations($packageName, $migrationPath);
        $this->assertCount(1, $pending);
        $this->assertEquals('2024_01_04_000004_create_fourth_table.php', $pending[0]);
    }

    public function testDiscoverMigrationsOnlyReturnsPHPFiles(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';

        $runner = new MigrationRunner($this->discoverer);

        file_put_contents($migrationPath . '/2024_01_01_000001_migration.php', '<?php return new class {};');
        file_put_contents($migrationPath . '/readme.txt', 'This is a readme');
        file_put_contents($migrationPath . '/notes.md', '# Notes');
        file_put_contents($migrationPath . '/.gitkeep', '');

        $migrations = $runner->discoverMigrations($migrationPath);

        $this->assertCount(1, $migrations);
        $this->assertEquals('2024_01_01_000001_migration.php', $migrations[0]);
    }

    public function testEmptyMigrationDirectory(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithMigrations($packageName);
        $migrationPath = $pluginDir . '/Database/Migrations';

        $runner = new MigrationRunner($this->discoverer);

        $migrations = $runner->discoverMigrations($migrationPath);
        $this->assertEmpty($migrations);

        $executed = $runner->migrate($packageName, $migrationPath);
        $this->assertEmpty($executed);

        $pending = $runner->getPendingMigrations($packageName, $migrationPath);
        $this->assertEmpty($pending);
    }

    public function testNonExistentMigrationDirectory(): void
    {
        $packageName = $this->generateRandomPackageName();
        $this->createTestPluginWithMigrations($packageName);
        $nonExistentPath = $this->tempDir . '/non_existent_migrations';

        $runner = new MigrationRunner($this->discoverer);

        $migrations = $runner->discoverMigrations($nonExistentPath);
        $this->assertEmpty($migrations);

        $pending = $runner->getPendingMigrations($packageName, $nonExistentPath);
        $this->assertEmpty($pending);
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
     * Create a test plugin with migrations directory and install.lock.
     */
    private function createTestPluginWithMigrations(string $packageName): string
    {
        $pluginDir = $this->tempDir . '/plugins/' . str_replace('/', '-', $packageName);
        mkdir($pluginDir . '/Database/Migrations', 0755, true);

        // Create plugin.json
        file_put_contents($pluginDir . '/plugin.json', json_encode([
            'name' => $packageName,
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT));

        // Create install.lock to mark as installed
        file_put_contents($pluginDir . '/install.lock', json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
            'migrations_executed' => [],
            'seeder_executed' => false,
        ], JSON_PRETTY_PRINT));

        return $pluginDir;
    }

    /**
     * Generate a migration file with tracking capability.
     */
    private function createMigrationFile(string $migrationPath, string $filename, string $trackingFile): string
    {
        $fullPath = $migrationPath . '/' . $filename;

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

        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    /**
     * Generate random package name.
     */
    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 100);
    }
}
