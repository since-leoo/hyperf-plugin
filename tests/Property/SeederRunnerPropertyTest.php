<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Tests\Property;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\SeederRunner;
use SinceLeoo\Plugin\ConfigWriter;

/**
 * Property-based tests for SeederRunner
 * 
 * Feature: hyperf-plugin-refactor
 * 
 * These tests verify universal properties that should hold for all valid inputs.
 */
class SeederRunnerPropertyTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private string $seederPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/seeder_runner_test_' . uniqid();
        mkdir($this->tempDir . '/config/autoload', 0755, true);
        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
        $this->seederPath = $this->tempDir . '/seeders';
        mkdir($this->seederPath, 0755, true);
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
     * Generate a seeder file with tracking capability
     * 
     * @param string $filename Seeder filename
     * @param string $trackingFile File to track execution
     * @param bool $shouldFail Whether the seeder should throw an exception
     * @return string Full path to the seeder file
     */
    private function createSeederFile(string $filename, string $trackingFile, bool $shouldFail = false): string
    {
        $fullPath = $this->seederPath . '/' . $filename;
        
        if ($shouldFail) {
            $content = <<<PHP
<?php
return new class {
    public function run(): void
    {
        throw new \RuntimeException("Seeder {$filename} failed intentionally");
    }
};
PHP;
        } else {
            $content = <<<PHP
<?php
return new class {
    public function run(): void
    {
        \$content = file_exists('{$trackingFile}') ? file_get_contents('{$trackingFile}') : '';
        file_put_contents('{$trackingFile}', \$content . "run:{$filename}\n");
    }
};
PHP;
        }
        
        file_put_contents($fullPath, $content);
        return $fullPath;
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
     * Property 9: Seeder Execution After Migrations
     * 
     * *For any* plugin with seeders defined, the seeder SHALL be executed
     * and the execution status SHALL be tracked in configuration.
     * 
     * This test verifies that seeders are properly discovered, executed,
     * and their status is recorded - which is the prerequisite for
     * "execution after migrations" (the ordering is handled by PluginManager).
     * 
     * **Validates: Requirements 14.2**
     * 
     * @dataProvider seederExecutionProvider
     */
    public function testSeederExecutionAndTracking(array $seederFilenames): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create seeder files
        foreach ($seederFilenames as $filename) {
            $this->createSeederFile($filename, $trackingFile);
        }

        // Verify seeder not executed initially
        $this->assertFalse(
            $runner->hasSeeded($packageName),
            "Seeder should not be marked as executed initially"
        );

        // Execute seeders
        $result = $runner->seed($packageName, $this->seederPath, false);

        // Verify execution was successful
        $this->assertTrue($result, "Seeder execution should succeed");

        // Verify seeder is marked as executed
        $this->assertTrue(
            $runner->hasSeeded($packageName),
            "Seeder should be marked as executed after seed()"
        );

        // Verify all seeders were actually executed
        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';
        $executionLog = array_filter(explode("\n", trim($trackingContent)));

        $this->assertCount(
            count($seederFilenames),
            $executionLog,
            "All seeders should be executed"
        );

        // Verify each seeder was executed
        foreach ($seederFilenames as $filename) {
            $this->assertStringContainsString(
                "run:{$filename}",
                $trackingContent,
                "Seeder {$filename} should have been executed"
            );
        }
    }

    /**
     * Data provider for seeder execution test - generates 100 test cases
     */
    public static function seederExecutionProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; $i++) {
            // Generate 1-5 random seeder filenames
            $numSeeders = rand(1, 5);
            $filenames = [];

            for ($j = 0; $j < $numSeeders; $j++) {
                $seederName = ucfirst(chr(ord('a') + rand(0, 25))) . 'Seeder' . rand(1, 100);
                $filenames[] = $seederName . '.php';
            }

            // Ensure unique filenames
            $filenames = array_unique($filenames);

            $testCases["iteration_{$i}"] = [$filenames];
        }

        return $testCases;
    }

    /**
     * Property 10: Seeder Failure Non-Blocking
     * 
     * *For any* plugin installation where seeder execution fails,
     * the installation SHALL continue successfully and the error SHALL be logged.
     * The seeder status should still be marked as executed (attempted).
     * 
     * **Validates: Requirements 14.4**
     * 
     * @dataProvider seederFailureNonBlockingProvider
     */
    public function testSeederFailureNonBlocking(array $seederFilenames, int $failingIndex): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create seeder files, with one that fails
        foreach ($seederFilenames as $index => $filename) {
            $shouldFail = ($index === $failingIndex);
            $this->createSeederFile($filename, $trackingFile, $shouldFail);
        }

        // Execute seeders - should return false due to failure but not throw
        $result = $runner->seed($packageName, $this->seederPath, false);

        // Verify execution returned false (indicating failure occurred)
        $this->assertFalse($result, "Seeder execution should return false when a seeder fails");

        // CRITICAL: Verify seeder is still marked as executed (non-blocking behavior)
        $this->assertTrue(
            $runner->hasSeeded($packageName),
            "Seeder should still be marked as executed even when failures occur (non-blocking)"
        );

        // Verify other seeders were still attempted (those before the failure)
        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';
        
        // Seeders are executed in sorted order, so we need to check which ones ran
        $sortedFilenames = $seederFilenames;
        sort($sortedFilenames, SORT_STRING);
        
        // Find the position of the failing seeder in sorted order
        $failingFilename = $seederFilenames[$failingIndex];
        $failingPositionInSorted = array_search($failingFilename, $sortedFilenames);
        
        // All seeders before the failing one (in sorted order) should have executed
        for ($i = 0; $i < $failingPositionInSorted; $i++) {
            $this->assertStringContainsString(
                "run:{$sortedFilenames[$i]}",
                $trackingContent,
                "Seeder {$sortedFilenames[$i]} should have been executed before the failing seeder"
            );
        }
    }

    /**
     * Data provider for seeder failure non-blocking test - generates 100 test cases
     */
    public static function seederFailureNonBlockingProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; $i++) {
            // Generate 2-5 random seeder filenames (need at least 2 to test non-blocking)
            $numSeeders = rand(2, 5);
            $filenames = [];

            for ($j = 0; $j < $numSeeders; $j++) {
                $seederName = ucfirst(chr(ord('a') + rand(0, 25))) . 'Seeder' . rand(1, 100);
                $filenames[] = $seederName . '.php';
            }

            // Ensure unique filenames
            $filenames = array_unique($filenames);
            $filenames = array_values($filenames);

            // Only add test case if we have at least 2 unique filenames
            if (count($filenames) >= 2) {
                // Pick a random seeder to fail
                $failingIndex = rand(0, count($filenames) - 1);
                $testCases["iteration_{$i}"] = [$filenames, $failingIndex];
            }
        }

        return $testCases;
    }

    /**
     * Test discoverSeeders only returns PHP files
     */
    public function testDiscoverSeedersOnlyReturnsPHPFiles(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);

        // Create various files
        file_put_contents($this->seederPath . '/ExampleSeeder.php', '<?php return new class { public function run(): void {} };');
        file_put_contents($this->seederPath . '/readme.txt', 'This is a readme');
        file_put_contents($this->seederPath . '/notes.md', '# Notes');
        file_put_contents($this->seederPath . '/.gitkeep', '');

        $seeders = $runner->discoverSeeders($this->seederPath);

        $this->assertCount(1, $seeders);
        $this->assertEquals('ExampleSeeder.php', $seeders[0]);
    }

    /**
     * Test empty seeder directory
     */
    public function testEmptySeederDirectory(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);
        $packageName = $this->generateRandomPackageName();

        $seeders = $runner->discoverSeeders($this->seederPath);
        $this->assertEmpty($seeders);

        // Empty directory should still succeed and mark as seeded
        $result = $runner->seed($packageName, $this->seederPath, false);
        $this->assertTrue($result);
        $this->assertTrue($runner->hasSeeded($packageName));
    }

    /**
     * Test non-existent seeder directory
     */
    public function testNonExistentSeederDirectory(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);
        $nonExistentPath = $this->tempDir . '/non_existent_seeders';

        $seeders = $runner->discoverSeeders($nonExistentPath);
        $this->assertEmpty($seeders);
    }

    /**
     * Test hasSeeded returns false for unknown package
     */
    public function testHasSeededReturnsFalseForUnknownPackage(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);

        $this->assertFalse($runner->hasSeeded('unknown/package'));
    }

    /**
     * Test seeders are executed in sorted order
     */
    public function testSeedersExecutedInSortedOrder(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $runner = new SeederRunner($configWriter);
        $packageName = $this->generateRandomPackageName();
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        // Create seeders with names that will sort differently
        $seederFilenames = [
            'ZSeeder.php',
            'ASeeder.php',
            'MSeeder.php',
        ];

        foreach ($seederFilenames as $filename) {
            $this->createSeederFile($filename, $trackingFile);
        }

        // Execute seeders
        $runner->seed($packageName, $this->seederPath, false);

        // Read the tracking file to verify execution order
        $trackingContent = file_get_contents($trackingFile);
        $executionLog = array_filter(explode("\n", trim($trackingContent)));

        // Extract the filenames from the execution log (format: "run:filename")
        $executedOrder = array_map(function ($line) {
            return str_replace('run:', '', $line);
        }, $executionLog);

        // Expected order is sorted alphabetically
        $expectedOrder = ['ASeeder.php', 'MSeeder.php', 'ZSeeder.php'];

        $this->assertEquals(
            $expectedOrder,
            $executedOrder,
            "Seeders should be executed in alphabetical order"
        );
    }
}
