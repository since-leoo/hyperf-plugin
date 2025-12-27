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
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;
use SinceLeoo\Plugin\SeederRunner;

/**
 * Property-based tests for SeederRunner.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * Seeder status is now stored in install.lock files in plugin directories.
 * @internal
 * @coversNothing
 */
class SeederRunnerPropertyTest extends TestCase
{
    private string $tempDir;

    private string $configPath;

    private ConfigWriter $configWriter;

    private PluginConfigReader $configReader;

    private PluginDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/seeder_runner_test_' . uniqid();
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
     * Property 9: Seeder Execution After Migrations.
     *
     * @dataProvider seederExecutionProvider
     */
    public function testSeederExecutionAndTracking(array $seederFilenames): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithSeeders($packageName);
        $seederPath = $pluginDir . '/Database/Seeders';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new SeederRunner($this->discoverer);

        foreach ($seederFilenames as $filename) {
            $this->createSeederFile($seederPath, $filename, $trackingFile);
        }

        $this->assertFalse($runner->hasSeeded($packageName));

        $result = $runner->seed($packageName, $seederPath, false);

        $this->assertTrue($result);
        $this->assertTrue($runner->hasSeeded($packageName));

        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';
        $executionLog = array_filter(explode("\n", trim($trackingContent)));

        $this->assertCount(count($seederFilenames), $executionLog);

        foreach ($seederFilenames as $filename) {
            $this->assertStringContainsString("run:{$filename}", $trackingContent);
        }
    }

    public static function seederExecutionProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $numSeeders = rand(1, 5);
            $filenames = [];
            for ($j = 0; $j < $numSeeders; ++$j) {
                $seederName = ucfirst(chr(ord('a') + rand(0, 25))) . 'Seeder' . rand(1, 100);
                $filenames[] = $seederName . '.php';
            }
            $filenames = array_unique($filenames);
            $testCases["iteration_{$i}"] = [$filenames];
        }
        return $testCases;
    }

    /**
     * Property 10: Seeder Failure Non-Blocking.
     *
     * @dataProvider seederFailureNonBlockingProvider
     */
    public function testSeederFailureNonBlocking(array $seederFilenames, int $failingIndex): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithSeeders($packageName);
        $seederPath = $pluginDir . '/Database/Seeders';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new SeederRunner($this->discoverer);

        foreach ($seederFilenames as $index => $filename) {
            $shouldFail = ($index === $failingIndex);
            $this->createSeederFile($seederPath, $filename, $trackingFile, $shouldFail);
        }

        $result = $runner->seed($packageName, $seederPath, false);

        $this->assertFalse($result);
        $this->assertTrue($runner->hasSeeded($packageName));

        $trackingContent = file_exists($trackingFile) ? file_get_contents($trackingFile) : '';

        $sortedFilenames = $seederFilenames;
        sort($sortedFilenames, SORT_STRING);

        $failingFilename = $seederFilenames[$failingIndex];
        $failingPositionInSorted = array_search($failingFilename, $sortedFilenames);

        for ($i = 0; $i < $failingPositionInSorted; ++$i) {
            $this->assertStringContainsString("run:{$sortedFilenames[$i]}", $trackingContent);
        }
    }

    public static function seederFailureNonBlockingProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $numSeeders = rand(2, 5);
            $filenames = [];
            for ($j = 0; $j < $numSeeders; ++$j) {
                $seederName = ucfirst(chr(ord('a') + rand(0, 25))) . 'Seeder' . rand(1, 100);
                $filenames[] = $seederName . '.php';
            }
            $filenames = array_unique($filenames);
            $filenames = array_values($filenames);
            if (count($filenames) >= 2) {
                $failingIndex = rand(0, count($filenames) - 1);
                $testCases["iteration_{$i}"] = [$filenames, $failingIndex];
            }
        }
        return $testCases;
    }

    public function testDiscoverSeedersOnlyReturnsPHPFiles(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithSeeders($packageName);
        $seederPath = $pluginDir . '/Database/Seeders';

        $runner = new SeederRunner($this->discoverer);

        file_put_contents($seederPath . '/ExampleSeeder.php', '<?php return new class { public function run(): void {} };');
        file_put_contents($seederPath . '/readme.txt', 'This is a readme');
        file_put_contents($seederPath . '/notes.md', '# Notes');
        file_put_contents($seederPath . '/.gitkeep', '');

        $seeders = $runner->discoverSeeders($seederPath);

        $this->assertCount(1, $seeders);
        $this->assertEquals('ExampleSeeder.php', $seeders[0]);
    }

    public function testEmptySeederDirectory(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithSeeders($packageName);
        $seederPath = $pluginDir . '/Database/Seeders';

        $runner = new SeederRunner($this->discoverer);

        $seeders = $runner->discoverSeeders($seederPath);
        $this->assertEmpty($seeders);

        $result = $runner->seed($packageName, $seederPath, false);
        $this->assertTrue($result);
        $this->assertTrue($runner->hasSeeded($packageName));
    }

    public function testNonExistentSeederDirectory(): void
    {
        $runner = new SeederRunner($this->discoverer);
        $nonExistentPath = $this->tempDir . '/non_existent_seeders';

        $seeders = $runner->discoverSeeders($nonExistentPath);
        $this->assertEmpty($seeders);
    }

    public function testHasSeededReturnsFalseForUnknownPackage(): void
    {
        $runner = new SeederRunner($this->discoverer);
        $this->assertFalse($runner->hasSeeded('unknown/package'));
    }

    public function testSeedersExecutedInSortedOrder(): void
    {
        $packageName = $this->generateRandomPackageName();
        $pluginDir = $this->createTestPluginWithSeeders($packageName);
        $seederPath = $pluginDir . '/Database/Seeders';
        $trackingFile = $this->tempDir . '/tracking_' . uniqid() . '.txt';

        $runner = new SeederRunner($this->discoverer);

        $seederFilenames = ['ZSeeder.php', 'ASeeder.php', 'MSeeder.php'];

        foreach ($seederFilenames as $filename) {
            $this->createSeederFile($seederPath, $filename, $trackingFile);
        }

        $runner->seed($packageName, $seederPath, false);

        $trackingContent = file_get_contents($trackingFile);
        $executionLog = array_filter(explode("\n", trim($trackingContent)));
        $executedOrder = array_map(fn ($line) => str_replace('run:', '', $line), $executionLog);

        $expectedOrder = ['ASeeder.php', 'MSeeder.php', 'ZSeeder.php'];

        $this->assertEquals($expectedOrder, $executedOrder);
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
     * Create a test plugin with seeders directory and install.lock.
     */
    private function createTestPluginWithSeeders(string $packageName): string
    {
        $pluginDir = $this->tempDir . '/plugins/' . str_replace('/', '-', $packageName);
        mkdir($pluginDir . '/Database/Seeders', 0755, true);

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
     * Generate a seeder file with tracking capability.
     */
    private function createSeederFile(string $seederPath, string $filename, string $trackingFile, bool $shouldFail = false): string
    {
        $fullPath = $seederPath . '/' . $filename;

        if ($shouldFail) {
            $content = <<<PHP
<?php
return new class {
    public function run(): void
    {
        throw new \\RuntimeException("Seeder {$filename} failed intentionally");
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
     * Generate random package name.
     */
    private function generateRandomPackageName(): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 100);
    }
}
