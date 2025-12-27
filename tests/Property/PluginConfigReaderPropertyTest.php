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
use SinceLeoo\Plugin\PluginConfigReader;

/**
 * Property-based tests for PluginConfigReader.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * @internal
 * @coversNothing
 */
class PluginConfigReaderPropertyTest extends TestCase
{
    private string $tempDir;

    private PluginConfigReader $reader;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/plugin_config_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->reader = new PluginConfigReader();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Property 5: Plugin.json Configuration Accuracy.
     *
     * *For any* plugin with a valid plugin.json, the PluginConfigReader SHALL correctly
     * parse all fields and return accurate default values for missing optional fields.
     *
     * **Validates: Requirements 2.1-2.7**
     *
     * @dataProvider configurationAccuracyProvider
     */
    public function testPluginJsonConfigurationAccuracy(array $inputConfig, bool $isMinimal): void
    {
        $pluginPath = $this->createPluginWithConfig($inputConfig);

        // Read the configuration
        $readConfig = $this->reader->read($pluginPath);

        // Verify all input fields are correctly parsed
        foreach ($inputConfig as $key => $value) {
            $this->assertArrayHasKey($key, $readConfig, "Field '{$key}' should be present in read config");
            $this->assertEquals($value, $readConfig[$key], "Field '{$key}' should have correct value");
        }

        // Verify validation passes for valid config
        $errors = $this->reader->validate($readConfig);
        $this->assertEmpty($errors, 'Valid config should pass validation: ' . implode(', ', $errors));

        // Verify default values are returned correctly for missing optional fields
        if ($isMinimal) {
            $this->assertEquals('', $this->reader->get($readConfig, 'description'));
            $this->assertEquals('', $this->reader->get($readConfig, 'author'));
            $this->assertEquals(0, $this->reader->get($readConfig, 'priority'));
            $this->assertEquals([], $this->reader->get($readConfig, 'dependencies'));
            $this->assertEquals(false, $this->reader->get($readConfig, 'rollback_on_uninstall'));
            $this->assertEquals(false, $this->reader->get($readConfig, 'enabled'));
        }
    }

    /**
     * Data provider for configuration accuracy test - generates 100 test cases.
     */
    public static function configurationAccuracyProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $isMinimal = rand(0, 1) === 1;

            if ($isMinimal) {
                // Minimal config with only required fields
                $config = [
                    'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i,
                    'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
                ];
            } else {
                // Full config with all fields
                $config = [
                    'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i,
                    'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
                    'description' => 'Test plugin description ' . $i,
                    'author' => 'Author ' . $i,
                    'priority' => rand(-100, 100),
                    'dependencies' => rand(0, 1) ? [$vendors[array_rand($vendors)] . '/dep-' . rand(1, 10)] : [],
                    'rollback_on_uninstall' => (bool) rand(0, 1),
                    'enabled' => (bool) rand(0, 1),
                ];
            }

            $testCases["iteration_{$i}"] = [$config, $isMinimal];
        }

        return $testCases;
    }

    /**
     * Test that missing required fields are detected.
     *
     * @dataProvider missingRequiredFieldsProvider
     */
    public function testValidationDetectsMissingRequiredFields(array $config, array $expectedMissingFields): void
    {
        $errors = $this->reader->validate($config);

        foreach ($expectedMissingFields as $field) {
            $found = false;
            foreach ($errors as $error) {
                if (str_contains($error, $field)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Should detect missing required field: {$field}");
        }
    }

    /**
     * Data provider for missing required fields test.
     */
    public static function missingRequiredFieldsProvider(): array
    {
        return [
            'missing_name' => [
                ['version' => '1.0.0'],
                ['name'],
            ],
            'missing_version' => [
                ['name' => 'vendor/plugin'],
                ['version'],
            ],
            'missing_both' => [
                [],
                ['name', 'version'],
            ],
            'empty_name' => [
                ['name' => '', 'version' => '1.0.0'],
                ['name'],
            ],
            'empty_version' => [
                ['name' => 'vendor/plugin', 'version' => ''],
                ['version'],
            ],
        ];
    }

    /**
     * Test convention-based directory detection for migrations.
     *
     * @dataProvider migrationDirectoryProvider
     */
    public function testMigrationDirectoryDetection(bool $createMigrationDir): void
    {
        $pluginPath = $this->tempDir . '/plugin_migration_' . uniqid();
        mkdir($pluginPath, 0755, true);

        if ($createMigrationDir) {
            mkdir($pluginPath . '/Database/Migrations', 0755, true);
        }

        $this->assertEquals($createMigrationDir, $this->reader->hasMigrations($pluginPath));

        if ($createMigrationDir) {
            $this->assertEquals(
                $pluginPath . '/Database/Migrations',
                $this->reader->getMigrationPath($pluginPath)
            );
        } else {
            $this->assertNull($this->reader->getMigrationPath($pluginPath));
        }
    }

    /**
     * Data provider for migration directory test - generates 100 test cases.
     */
    public static function migrationDirectoryProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $testCases["iteration_{$i}"] = [(bool) rand(0, 1)];
        }
        return $testCases;
    }

    /**
     * Test convention-based directory detection for seeders.
     *
     * @dataProvider seederDirectoryProvider
     */
    public function testSeederDirectoryDetection(bool $createSeederDir): void
    {
        $pluginPath = $this->tempDir . '/plugin_seeder_' . uniqid();
        mkdir($pluginPath, 0755, true);

        if ($createSeederDir) {
            mkdir($pluginPath . '/Database/Seeders', 0755, true);
        }

        $this->assertEquals($createSeederDir, $this->reader->hasSeeders($pluginPath));

        if ($createSeederDir) {
            $this->assertEquals(
                $pluginPath . '/Database/Seeders',
                $this->reader->getSeederPath($pluginPath)
            );
        } else {
            $this->assertNull($this->reader->getSeederPath($pluginPath));
        }
    }

    /**
     * Data provider for seeder directory test - generates 100 test cases.
     */
    public static function seederDirectoryProvider(): array
    {
        $testCases = [];
        for ($i = 0; $i < 100; ++$i) {
            $testCases["iteration_{$i}"] = [(bool) rand(0, 1)];
        }
        return $testCases;
    }

    /**
     * Test that read returns empty array for non-existent plugin.json.
     */
    public function testReadReturnsEmptyForNonExistentFile(): void
    {
        $pluginPath = $this->tempDir . '/non_existent_plugin';
        mkdir($pluginPath, 0755, true);

        $config = $this->reader->read($pluginPath);

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    /**
     * Test that read returns empty array for invalid JSON.
     */
    public function testReadReturnsEmptyForInvalidJson(): void
    {
        $pluginPath = $this->tempDir . '/invalid_json_plugin';
        mkdir($pluginPath, 0755, true);
        file_put_contents($pluginPath . '/plugin.json', 'invalid json content');

        $config = $this->reader->read($pluginPath);

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    /**
     * Test get method with custom default value.
     */
    public function testGetWithCustomDefault(): void
    {
        $config = ['name' => 'vendor/plugin', 'version' => '1.0.0'];

        $this->assertEquals('custom_default', $this->reader->get($config, 'nonexistent', 'custom_default'));
        $this->assertEquals('vendor/plugin', $this->reader->get($config, 'name', 'default'));
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
     * Generate a random valid plugin.json configuration.
     */
    private function generateValidConfig(): array
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        return [
            'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 100),
            'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
            'description' => 'Test plugin description ' . rand(1, 1000),
            'author' => 'Author ' . rand(1, 100),
            'priority' => rand(-100, 100),
            'dependencies' => rand(0, 1) ? [$vendors[array_rand($vendors)] . '/dep-' . rand(1, 10)] : [],
            'rollback_on_uninstall' => (bool) rand(0, 1),
            'enabled' => (bool) rand(0, 1),
        ];
    }

    /**
     * Generate a minimal valid plugin.json configuration (only required fields).
     */
    private function generateMinimalConfig(): array
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        return [
            'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . rand(1, 100),
            'version' => rand(1, 10) . '.' . rand(0, 99) . '.' . rand(0, 99),
        ];
    }

    /**
     * Create a plugin directory with plugin.json.
     */
    private function createPluginWithConfig(array $config): string
    {
        $pluginPath = $this->tempDir . '/plugin_' . uniqid();
        mkdir($pluginPath, 0755, true);

        file_put_contents(
            $pluginPath . '/plugin.json',
            json_encode($config, JSON_PRETTY_PRINT)
        );

        return $pluginPath;
    }
}
