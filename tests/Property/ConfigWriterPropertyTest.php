<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Tests\Property;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\ConfigWriter;

/**
 * Property-based tests for ConfigWriter
 * 
 * Feature: hyperf-plugin-refactor
 * 
 * These tests verify universal properties that should hold for all valid inputs.
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
     * Generate random plugin configuration for testing
     */
    private function generateRandomPluginConfig(): array
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        return [
            'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
            'path' => '/path/to/' . $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)],
            'installed_at' => date('Y-m-d H:i:s', rand(strtotime('2020-01-01'), time())),
            'plugin_class' => 'Vendor\\Plugin\\Plugin' . rand(1, 100),
        ];
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
     * Property 1: Configuration Round-Trip Consistency
     * 
     * *For any* valid plugin configuration array, writing it to the configuration file
     * and then reading it back SHALL produce an equivalent configuration array.
     * 
     * **Validates: Requirements 2.5**
     * 
     * @dataProvider configurationRoundTripProvider
     */
    public function testConfigurationRoundTripConsistency(array $pluginConfig): void
    {
        $writer = new ConfigWriter($this->configPath);
        $packageName = $this->generateRandomPackageName();
        
        // Write configuration
        $writer->updatePluginConfig($packageName, $pluginConfig);
        
        // Read it back
        $readConfig = $writer->getConfig();
        
        // Verify round-trip consistency
        $this->assertArrayHasKey('installed', $readConfig);
        $this->assertArrayHasKey($packageName, $readConfig['installed']);
        $this->assertEquals($pluginConfig, $readConfig['installed'][$packageName]);
    }

    /**
     * Data provider for round-trip test - generates 100 random configurations
     */
    public static function configurationRoundTripProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            $config = [
                'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
                'path' => '/path/to/' . $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)],
                'installed_at' => date('Y-m-d H:i:s', rand(strtotime('2020-01-01'), time())),
                'plugin_class' => 'Vendor\\Plugin\\Plugin' . rand(1, 100),
            ];
            $testCases["iteration_{$i}"] = [$config];
        }
        
        return $testCases;
    }


    /**
     * Property 2: Configuration Preservation on Update
     * 
     * *For any* existing plugin configuration and any single plugin update operation,
     * all configuration entries not related to the updated plugin SHALL remain unchanged
     * after the update.
     * 
     * **Validates: Requirements 2.6**
     * 
     * @dataProvider configurationPreservationProvider
     */
    public function testConfigurationPreservationOnUpdate(
        array $existingPlugins,
        string $updatePackageName,
        array $updateConfig
    ): void {
        $writer = new ConfigWriter($this->configPath);
        
        // Set up existing plugins
        foreach ($existingPlugins as $packageName => $config) {
            $writer->updatePluginConfig($packageName, $config);
            $writer->setPluginEnabled($packageName, (bool) rand(0, 1));
        }
        
        // Get state before update
        $configBefore = $writer->getConfig();
        
        // Perform update on a specific plugin
        $writer->updatePluginConfig($updatePackageName, $updateConfig);
        
        // Get state after update
        $configAfter = $writer->getConfig();
        
        // Verify all other plugins remain unchanged
        foreach ($existingPlugins as $packageName => $config) {
            if ($packageName !== $updatePackageName) {
                $this->assertArrayHasKey($packageName, $configAfter['installed']);
                $this->assertEquals(
                    $configBefore['installed'][$packageName],
                    $configAfter['installed'][$packageName],
                    "Plugin {$packageName} should remain unchanged after updating {$updatePackageName}"
                );
                
                // Check enabled status preserved
                if (isset($configBefore['enabled'][$packageName])) {
                    $this->assertEquals(
                        $configBefore['enabled'][$packageName],
                        $configAfter['enabled'][$packageName],
                        "Enabled status for {$packageName} should remain unchanged"
                    );
                }
            }
        }
        
        // Verify the updated plugin has new config
        $this->assertEquals($updateConfig, $configAfter['installed'][$updatePackageName]);
    }

    /**
     * Data provider for preservation test - generates 100 test scenarios
     */
    public static function configurationPreservationProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        
        for ($i = 0; $i < 100; $i++) {
            // Generate 2-5 existing plugins
            $numPlugins = rand(2, 5);
            $existingPlugins = [];
            
            for ($j = 0; $j < $numPlugins; $j++) {
                $packageName = $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $j . '-' . $i;
                $existingPlugins[$packageName] = [
                    'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
                    'path' => '/path/to/' . $packageName,
                    'installed_at' => date('Y-m-d H:i:s', rand(strtotime('2020-01-01'), time())),
                    'plugin_class' => 'Vendor\\Plugin\\Plugin' . $j,
                ];
            }
            
            // Pick one to update or add a new one
            $packageNames = array_keys($existingPlugins);
            $updatePackageName = rand(0, 1) 
                ? $packageNames[array_rand($packageNames)]
                : 'new-vendor/new-plugin-' . $i;
            
            $updateConfig = [
                'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
                'path' => '/updated/path/' . $updatePackageName,
                'installed_at' => date('Y-m-d H:i:s'),
                'plugin_class' => 'Updated\\Plugin\\Class',
            ];
            
            $testCases["iteration_{$i}"] = [$existingPlugins, $updatePackageName, $updateConfig];
        }
        
        return $testCases;
    }
}
