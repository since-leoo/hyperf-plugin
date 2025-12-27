<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Tests\Property;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\MigrationRunner;
use SinceLeoo\Plugin\ConfigWriter;

/**
 * Property-based tests for MigrationRunner
 * 
 * Feature: hyperf-plugin-refactor
 * 
 * These tests verify universal properties that should hold for all valid inputs.
 */
class MigrationRunnerPropertyTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private string $migrationPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/migration_runner_test_' . uniqid();
        mkdir($this->tempDir . '/config/autoload', 0755, true);
        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
        $this->migrationPath = $this->tempDir . '/migrations';
        mkdir($this->migrationPath, 0755, true);
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
     * Generate a migration file with tracking capability
     * 
     * @param string $filename Migration filename
     * @param string $trackingFile File to track execution order
     * @return string Full path to the migration file
     */
    private function createMigrationFile(string $filename, string $trackingFile): string
    {
        $fullPath = $this->migrationPath . '/' . $filename;
        
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
     * Generate random migration filenames in standard format
     * 
     * @param int $count Number of migrations to generate
     * @return array Array of migration filenames
     */
    private function generateRandomMigrationFilenames(int $count): array
    {
        $filenames = [];
        $baseTimestamp = strtotime('2024-01-01');
        
        for ($i = 0; $i < $count; $i++) {
            $timestamp = date('Y_m_d_His', $baseTimestamp + ($i * 86400) + rand(0, 86399));
            $tableName = 'table_' . chr(ord('a') + rand(0, 25)) . rand(1, 100);
            $filenames[] = $timestamp . '_create_' . $tableName . '.php';
        }
        
        return $filenames;
    }

    /**
     * Generate random package name
     */
    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 100);
    }

    /**
     * Property 6: Migration Execution on Install
     * 
     * *For any* plugin with migrations defined, when the plugin is installed,
     * all pending migrations SHALL be executed and tracked.
     * 
     * **Validates: Requirements 13.2, 13.4, 13.5**
     * 
     * @dataProvider migrationExecutionOnInstallProvider
     */
    public function testMigrationExecutionOnInstall(array $migrationFilenames): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create migration files
        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($filename, $trackingFile);
        }

        // Verify no migrations executed initially
        $this->assertEmpty($runner->getExecutedMigrations($packageName));

        // Execute migrations
        $executedMigrations = $runner->migrate($packageName, $this->migrationPath);

        // Verify all migrations were executed
        $this->assertCount(
            count($migrationFilenames),
            $executedMigrations,
            "All pending migrations should be executed"
        );

        // Verify migrations are tracked in config
        $trackedMigrations = $runner->getExecutedMigrations($packageName);
        $this->assertCount(
            count($migrationFilenames),
            $trackedMigrations,
            "All executed migrations should be tracked"
        );

        // Verify each migration was executed
        foreach ($migrationFilenames as $filename) {
            $this->assertContains(
                $filename,
                $executedMigrations,
                "Migration {$filename} should be in executed list"
            );
            $this->assertContains(
                $filename,
                $trackedMigrations,
                "Migration {$filename} should be tracked"
            );
        }

        // Verify no pending migrations remain
        $pendingMigrations = $runner->getPendingMigrations($packageName, $this->migrationPath);
        $this->assertEmpty(
            $pendingMigrations,
            "No pending migrations should remain after execution"
        );
    }

    /**
     * Data provider for migration execution on install test - generates 100 test cases
     */
    public static function migrationExecutionOnInstallProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; $i++) {
            // Generate 1-5 random migration filenames
            $numMigrations = rand(1, 5);
            $filenames = [];
            $baseTimestamp = strtotime('2024-01-01');

            for ($j = 0; $j < $numMigrations; $j++) {
                $timestamp = date('Y_m_d_His', $baseTimestamp + ($j * 86400) + rand(0, 86399));
                $tableName = 'table_' . chr(ord('a') + rand(0, 25)) . rand(1, 100);
                $filenames[] = $timestamp . '_create_' . $tableName . '.php';
            }

            $testCases["iteration_{$i}"] = [$filenames];
        }

        return $testCases;
    }

    /**
     * Property 7: Migration Execution Order
     * 
     * *For any* plugin with multiple migration files, the Migration_Runner SHALL
     * execute them in ascending filename order during installation.
     * 
     * **Validates: Requirements 13.6**
     * 
     * @dataProvider migrationExecutionOrderProvider
     */
    public function testMigrationExecutionOrder(array $migrationFilenames): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create migration files
        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($filename, $trackingFile);
        }

        // Execute migrations
        $runner->migrate($packageName, $this->migrationPath);

        // Read the tracking file to verify execution order
        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';
        $executionLog = array_filter(explode("\n", trim($trackingContent)));

        // Extract the filenames from the execution log (format: "up:filename")
        $executedOrder = array_map(function ($line) {
            return str_replace('up:', '', $line);
        }, $executionLog);

        // Sort the original filenames to get expected order
        $expectedOrder = $migrationFilenames;
        sort($expectedOrder, SORT_STRING);

        // Verify execution order matches ascending filename order
        $this->assertEquals(
            $expectedOrder,
            $executedOrder,
            "Migrations should be executed in ascending filename order"
        );
    }

    /**
     * Data provider for migration execution order test - generates 100 test cases
     */
    public static function migrationExecutionOrderProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; $i++) {
            // Generate 2-6 random migration filenames (need at least 2 to test order)
            $numMigrations = rand(2, 6);
            $filenames = [];
            $baseTimestamp = strtotime('2024-01-01');

            for ($j = 0; $j < $numMigrations; $j++) {
                // Use random timestamps to ensure order testing is meaningful
                $randomDays = rand(0, 365);
                $randomSeconds = rand(0, 86399);
                $timestamp = date('Y_m_d_His', $baseTimestamp + ($randomDays * 86400) + $randomSeconds);
                $tableName = 'table_' . chr(ord('a') + rand(0, 25)) . rand(1, 100);
                $filenames[] = $timestamp . '_create_' . $tableName . '.php';
            }

            // Ensure unique filenames
            $filenames = array_unique($filenames);

            // Only add test case if we have at least 2 unique filenames
            if (count($filenames) >= 2) {
                $testCases["iteration_{$i}"] = [$filenames];
            }
        }

        return $testCases;
    }

    /**
     * Test rollback executes migrations in reverse order
     * 
     * **Validates: Requirements 15.7**
     */
    public function testRollbackExecutesInReverseOrder(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create migration files
        $migrationFilenames = [
            '2024_01_01_000001_create_first_table.php',
            '2024_01_02_000002_create_second_table.php',
            '2024_01_03_000003_create_third_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($filename, $trackingFile);
        }

        // Execute migrations first
        $runner->migrate($packageName, $this->migrationPath);

        // Clear tracking file for rollback tracking
        file_put_contents($trackingFile, '');

        // Execute rollback
        $rolledBackMigrations = $runner->rollback($packageName, $this->migrationPath);

        // Read the tracking file to verify rollback order
        $trackingContent = file_get_contents($trackingFile);
        $rollbackLog = array_filter(explode("\n", trim($trackingContent)));

        // Extract the filenames from the rollback log (format: "down:filename")
        $rollbackOrder = array_map(function ($line) {
            return str_replace('down:', '', $line);
        }, $rollbackLog);

        // Expected order is reverse of ascending filename order
        $expectedOrder = $migrationFilenames;
        rsort($expectedOrder, SORT_STRING);

        // Verify rollback order matches descending filename order
        $this->assertEquals(
            $expectedOrder,
            $rollbackOrder,
            "Rollback should execute in descending filename order"
        );

        // Verify all migrations were rolled back
        $this->assertCount(
            count($migrationFilenames),
            $rolledBackMigrations,
            "All migrations should be rolled back"
        );

        // Verify executed migrations list is cleared
        $executedMigrations = $runner->getExecutedMigrations($packageName);
        $this->assertEmpty(
            $executedMigrations,
            "Executed migrations should be cleared after rollback"
        );
    }

    /**
     * Test that running migrate twice doesn't re-execute migrations
     */
    public function testMigrateIsIdempotent(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create migration files
        $migrationFilenames = [
            '2024_01_01_000001_create_test_table.php',
            '2024_01_02_000002_create_another_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($filename, $trackingFile);
        }

        // Execute migrations first time
        $firstRun = $runner->migrate($packageName, $this->migrationPath);
        $this->assertCount(2, $firstRun);

        // Execute migrations second time
        $secondRun = $runner->migrate($packageName, $this->migrationPath);
        $this->assertEmpty($secondRun, "Second migrate call should not execute any migrations");

        // Verify tracking file shows migrations were only executed once
        $trackingContent = file_get_contents($trackingFile);
        $executionCount = substr_count($trackingContent, 'up:');
        $this->assertEquals(2, $executionCount, "Each migration should only be executed once");
    }

    /**
     * Test getPendingMigrations returns correct list
     */
    public function testGetPendingMigrationsReturnsCorrectList(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create migration files
        $migrationFilenames = [
            '2024_01_01_000001_create_first_table.php',
            '2024_01_02_000002_create_second_table.php',
            '2024_01_03_000003_create_third_table.php',
        ];

        foreach ($migrationFilenames as $filename) {
            $this->createMigrationFile($filename, $trackingFile);
        }

        // Initially all should be pending
        $pending = $runner->getPendingMigrations($packageName, $this->migrationPath);
        $this->assertCount(3, $pending);

        // Execute migrations
        $runner->migrate($packageName, $this->migrationPath);

        // Now none should be pending
        $pending = $runner->getPendingMigrations($packageName, $this->migrationPath);
        $this->assertEmpty($pending);

        // Add a new migration file
        $this->createMigrationFile('2024_01_04_000004_create_fourth_table.php', $trackingFile);

        // Only the new one should be pending
        $pending = $runner->getPendingMigrations($packageName, $this->migrationPath);
        $this->assertCount(1, $pending);
        $this->assertEquals('2024_01_04_000004_create_fourth_table.php', $pending[0]);
    }

    /**
     * Test discoverMigrations only returns PHP files
     */
    public function testDiscoverMigrationsOnlyReturnsPHPFiles(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);

        // Create various files
        file_put_contents($this->migrationPath . '/2024_01_01_000001_migration.php', '<?php return new class {};');
        file_put_contents($this->migrationPath . '/readme.txt', 'This is a readme');
        file_put_contents($this->migrationPath . '/notes.md', '# Notes');
        file_put_contents($this->migrationPath . '/.gitkeep', '');

        $migrations = $runner->discoverMigrations($this->migrationPath);

        $this->assertCount(1, $migrations);
        $this->assertEquals('2024_01_01_000001_migration.php', $migrations[0]);
    }

    /**
     * Test empty migration directory
     */
    public function testEmptyMigrationDirectory(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();

        $migrations = $runner->discoverMigrations($this->migrationPath);
        $this->assertEmpty($migrations);

        $executed = $runner->migrate($packageName, $this->migrationPath);
        $this->assertEmpty($executed);

        $pending = $runner->getPendingMigrations($packageName, $this->migrationPath);
        $this->assertEmpty($pending);
    }

    /**
     * Test non-existent migration directory
     */
    public function testNonExistentMigrationDirectory(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new MigrationRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $nonExistentPath = $this->tempDir . '/non_existent_migrations';

        $migrations = $runner->discoverMigrations($nonExistentPath);
        $this->assertEmpty($migrations);

        $pending = $runner->getPendingMigrations($packageName, $nonExistentPath);
        $this->assertEmpty($pending);
    }
}
