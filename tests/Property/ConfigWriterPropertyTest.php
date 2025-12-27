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

/**
 * Property-based tests for ConfigWriter.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * ConfigWriter now only manages enabled status. Installation status is tracked via install.lock files.
 * @internal
 * @coversNothing
 */
class ConfigWriterPropertyTest extends TestCase
{
    private string $tempDir;

    private string $configPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/plugin_test_' . uniqid();
        mkdir($this->tempDir . '/config/autoload', 0755, true);
        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Property 1: Enabled Status Round-Trip Consistency.
     *
     * *For any* plugin enabled status, writing it to the configuration file
     * and then reading it back SHALL produce the same status.
     *
     * **Validates: Requirements 2.5**
     *
     * @dataProvider enabledStatusRoundTripProvider
     */
    public function testEnabledStatusRoundTripConsistency(bool $enabled): void
    {
        $writer = new ConfigWriter($this->configPath);
        $packageName = $this->generateRandomPackageName();

        // Write enabled status
        $writer->setPluginEnabled($packageName, $enabled);

        // Read it back
        $readConfig = $writer->getConfig();

        // Verify round-trip consistency
        $this->assertArrayHasKey('enabled', $readConfig);
        $this->assertArrayHasKey($packageName, $readConfig['enabled']);
        $this->assertEquals($enabled, $readConfig['enabled'][$packageName]);
    }

    /**
     * Data provider for round-trip test - generates 100 random configurations.
     */
    public static function enabledStatusRoundTripProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; ++$i) {
            $testCases["iteration_{$i}"] = [(bool) rand(0, 1)];
        }

        return $testCases;
    }

    /**
     * Property 2: Enabled Status Preservation on Update.
     *
     * *For any* existing plugin enabled statuses and any single plugin update operation,
     * all enabled statuses not related to the updated plugin SHALL remain unchanged
     * after the update.
     *
     * **Validates: Requirements 2.6**
     *
     * @dataProvider enabledStatusPreservationProvider
     */
    public function testEnabledStatusPreservationOnUpdate(
        array $existingPlugins,
        string $updatePackageName,
        bool $updateEnabled
    ): void {
        $writer = new ConfigWriter($this->configPath);

        // Set up existing plugins
        foreach ($existingPlugins as $packageName => $enabled) {
            $writer->setPluginEnabled($packageName, $enabled);
        }

        // Get state before update
        $configBefore = $writer->getConfig();

        // Perform update on a specific plugin
        $writer->setPluginEnabled($updatePackageName, $updateEnabled);

        // Get state after update
        $configAfter = $writer->getConfig();

        // Verify all other plugins remain unchanged
        foreach ($existingPlugins as $packageName => $enabled) {
            if ($packageName !== $updatePackageName) {
                $this->assertEquals(
                    $configBefore['enabled'][$packageName],
                    $configAfter['enabled'][$packageName],
                    "Enabled status for {$packageName} should remain unchanged"
                );
            }
        }

        // Verify the updated plugin has new status
        $this->assertEquals($updateEnabled, $configAfter['enabled'][$updatePackageName]);
    }

    /**
     * Data provider for preservation test - generates 100 test scenarios.
     */
    public static function enabledStatusPreservationProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            // Generate 2-5 existing plugins
            $numPlugins = rand(2, 5);
            $existingPlugins = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $j . '-' . $i;
                $existingPlugins[$packageName] = (bool) rand(0, 1);
            }

            // Pick one to update or add a new one
            $packageNames = array_keys($existingPlugins);
            $updatePackageName = rand(0, 1)
                ? $packageNames[array_rand($packageNames)]
                : 'new-vendor/new-plugin-' . $i;

            $updateEnabled = (bool) rand(0, 1);

            $testCases["iteration_{$i}"] = [$existingPlugins, $updatePackageName, $updateEnabled];
        }

        return $testCases;
    }

    /**
     * Property 3: Remove Plugin Enabled Status.
     *
     * *For any* plugin with enabled status, removing it SHALL remove only that plugin's
     * status while preserving all other plugins' statuses.
     *
     * @dataProvider removePluginEnabledProvider
     */
    public function testRemovePluginEnabledPreservesOthers(
        array $existingPlugins,
        string $removePackageName
    ): void {
        $writer = new ConfigWriter($this->configPath);

        // Set up existing plugins
        foreach ($existingPlugins as $packageName => $enabled) {
            $writer->setPluginEnabled($packageName, $enabled);
        }

        // Get state before removal
        $configBefore = $writer->getConfig();

        // Remove one plugin's enabled status
        $writer->removePluginEnabled($removePackageName);

        // Get state after removal
        $configAfter = $writer->getConfig();

        // Verify the removed plugin is gone
        $this->assertArrayNotHasKey($removePackageName, $configAfter['enabled']);

        // Verify all other plugins remain unchanged
        foreach ($existingPlugins as $packageName => $enabled) {
            if ($packageName !== $removePackageName) {
                $this->assertEquals(
                    $configBefore['enabled'][$packageName],
                    $configAfter['enabled'][$packageName],
                    "Enabled status for {$packageName} should remain unchanged"
                );
            }
        }
    }

    /**
     * Data provider for remove plugin enabled test.
     */
    public static function removePluginEnabledProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            // Generate 2-5 existing plugins
            $numPlugins = rand(2, 5);
            $existingPlugins = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $j . '-' . $i;
                $existingPlugins[$packageName] = (bool) rand(0, 1);
            }

            // Pick one to remove
            $packageNames = array_keys($existingPlugins);
            $removePackageName = $packageNames[array_rand($packageNames)];

            $testCases["iteration_{$i}"] = [$existingPlugins, $removePackageName];
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
