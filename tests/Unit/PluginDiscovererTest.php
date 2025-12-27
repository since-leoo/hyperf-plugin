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

namespace SinceLeoo\Plugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SinceLeoo\Plugin\ConfigWriter;
use SinceLeoo\Plugin\PluginConfigReader;
use SinceLeoo\Plugin\PluginDiscoverer;

/**
 * Unit tests for PluginDiscoverer.
 *
 * Feature: hyperf-plugin-refactor
 *
 * Tests plugin discovery logic and plugin.json parsing.
 * **Validates: Requirements 5.1, 7.1**
 * @internal
 * @coversNothing
 */
class PluginDiscovererTest extends TestCase
{
    private string $tempDir;

    private PluginDiscoverer $discoverer;

    private PluginConfigReader $configReader;

    private ConfigWriter $configWriter;

    private string $configPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/plugin_discoverer_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/plugins', 0755, true);
        mkdir($this->tempDir . '/config/autoload', 0755, true);

        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
        $this->configReader = new PluginConfigReader();
        $this->configWriter = new ConfigWriter($this->configPath);
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
     * Test discovering local plugins with plugin.json.
     */
    public function testDiscoverLocalPluginsWithPluginJson(): void
    {
        $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
            'description' => 'Test plugin description',
            'author' => 'Test Author',
            'priority' => 10,
            'dependencies' => ['vendor/other-plugin'],
        ]);

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(1, $plugins);
        $this->assertEquals('vendor/test-plugin', $plugins[0]['name']);
        $this->assertEquals('1.0.0', $plugins[0]['version']);
        $this->assertEquals('Test plugin description', $plugins[0]['description']);
        $this->assertEquals('Test Author', $plugins[0]['author']);
        $this->assertEquals(10, $plugins[0]['priority']);
        $this->assertEquals(['vendor/other-plugin'], $plugins[0]['dependencies']);
        $this->assertFalse($plugins[0]['installed']);
        $this->assertFalse($plugins[0]['enabled']);
    }

    /**
     * Test discovering local plugins with composer.json fallback.
     */
    public function testDiscoverLocalPluginsWithComposerFallback(): void
    {
        $this->createPluginWithComposer('legacy-plugin', [
            'name' => 'vendor/legacy-plugin',
            'version' => '2.0.0',
            'description' => 'Legacy plugin',
        ]);

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(1, $plugins);
        $this->assertEquals('vendor/legacy-plugin', $plugins[0]['name']);
        $this->assertEquals('2.0.0', $plugins[0]['version']);
        $this->assertEquals('Legacy plugin', $plugins[0]['description']);
        $this->assertEquals('', $plugins[0]['author']);
        $this->assertEquals(0, $plugins[0]['priority']);
        $this->assertEquals([], $plugins[0]['dependencies']);
    }

    /**
     * Test discovering multiple plugins.
     */
    public function testDiscoverMultiplePlugins(): void
    {
        $this->createPluginWithJson('plugin-a', [
            'name' => 'vendor/plugin-a',
            'version' => '1.0.0',
        ]);

        $this->createPluginWithJson('plugin-b', [
            'name' => 'vendor/plugin-b',
            'version' => '2.0.0',
        ]);

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(2, $plugins);

        $names = array_column($plugins, 'name');
        $this->assertContains('vendor/plugin-a', $names);
        $this->assertContains('vendor/plugin-b', $names);
    }

    /**
     * Test empty plugins directory.
     */
    public function testDiscoverEmptyPluginsDirectory(): void
    {
        $plugins = $this->discoverer->discoverLocalPlugins();
        $this->assertEmpty($plugins);
    }

    /**
     * Test non-existent plugins directory.
     */
    public function testDiscoverNonExistentPluginsDirectory(): void
    {
        $discoverer = new PluginDiscoverer(
            $this->configReader,
            $this->configWriter,
            $this->tempDir,
            'non_existent_plugins'
        );

        $plugins = $discoverer->discoverLocalPlugins();
        $this->assertEmpty($plugins);
    }

    /**
     * Test getInstalledPlugins returns empty when no plugins installed.
     */
    public function testGetInstalledPluginsEmpty(): void
    {
        $installed = $this->discoverer->getInstalledPlugins();
        $this->assertEmpty($installed);
    }

    /**
     * Test getInstalledPlugins returns installed plugins (via install.lock).
     */
    public function testGetInstalledPluginsReturnsInstalled(): void
    {
        // Create plugin with install.lock file
        $pluginPath = $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        // Create install.lock file to mark as installed
        file_put_contents($pluginPath . '/install.lock', json_encode([
            'installed_at' => '2024-01-01 00:00:00',
            'version' => '1.0.0',
            'migrations_executed' => [],
            'seeder_executed' => false,
        ]));

        $installed = $this->discoverer->getInstalledPlugins();

        $this->assertArrayHasKey('vendor/test-plugin', $installed);
        $this->assertEquals('1.0.0', $installed['vendor/test-plugin']['version']);
    }

    /**
     * Test isInstalled returns true for installed plugin (via install.lock).
     */
    public function testIsInstalledReturnsTrueForInstalledPlugin(): void
    {
        // Create plugin with install.lock file
        $pluginPath = $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        // Create install.lock file
        file_put_contents($pluginPath . '/install.lock', json_encode([
            'installed_at' => '2024-01-01 00:00:00',
            'version' => '1.0.0',
        ]));

        $this->assertTrue($this->discoverer->isInstalled('vendor/test-plugin'));
    }

    /**
     * Test isInstalled returns false for non-installed plugin.
     */
    public function testIsInstalledReturnsFalseForNonInstalledPlugin(): void
    {
        $this->assertFalse($this->discoverer->isInstalled('vendor/non-existent'));
    }

    /**
     * Test isInstalled returns false for plugin without install.lock.
     */
    public function testIsInstalledReturnsFalseForPluginWithoutLockFile(): void
    {
        // Create plugin without install.lock file
        $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        $this->assertFalse($this->discoverer->isInstalled('vendor/test-plugin'));
    }

    /**
     * Test isEnabled returns true for enabled plugin.
     */
    public function testIsEnabledReturnsTrueForEnabledPlugin(): void
    {
        $this->configWriter->setPluginEnabled('vendor/test-plugin', true);

        $this->assertTrue($this->discoverer->isEnabled('vendor/test-plugin'));
    }

    /**
     * Test isEnabled returns false for disabled plugin.
     */
    public function testIsEnabledReturnsFalseForDisabledPlugin(): void
    {
        $this->configWriter->setPluginEnabled('vendor/test-plugin', false);

        $this->assertFalse($this->discoverer->isEnabled('vendor/test-plugin'));
    }

    /**
     * Test isEnabled returns false for non-configured plugin.
     */
    public function testIsEnabledReturnsFalseForNonConfiguredPlugin(): void
    {
        $this->assertFalse($this->discoverer->isEnabled('vendor/non-existent'));
    }

    /**
     * Test getPluginJsonConfig returns config for local plugin.
     */
    public function testGetPluginJsonConfigForLocalPlugin(): void
    {
        $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
            'description' => 'Test description',
        ]);

        $config = $this->discoverer->getPluginJsonConfig('vendor/test-plugin');

        $this->assertEquals('vendor/test-plugin', $config['name']);
        $this->assertEquals('1.0.0', $config['version']);
        $this->assertEquals('Test description', $config['description']);
    }

    /**
     * Test getPluginJsonConfig returns empty for non-existent plugin.
     */
    public function testGetPluginJsonConfigReturnsEmptyForNonExistent(): void
    {
        $config = $this->discoverer->getPluginJsonConfig('vendor/non-existent');
        $this->assertEmpty($config);
    }

    /**
     * Test getPluginPath returns path for local plugin.
     */
    public function testGetPluginPathForLocalPlugin(): void
    {
        $expectedPath = $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        $path = $this->discoverer->getPluginPath('vendor/test-plugin');

        $this->assertEquals($expectedPath, $path);
    }

    /**
     * Test getPluginPath returns path from vendor directory.
     */
    public function testGetPluginPathFromVendorDirectory(): void
    {
        $pluginPath = $this->tempDir . '/vendor/vendor/installed-plugin';
        mkdir($pluginPath, 0755, true);

        $path = $this->discoverer->getPluginPath('vendor/installed-plugin');

        $this->assertEquals($pluginPath, $path);
    }

    /**
     * Test getPluginPath returns null for non-existent plugin.
     */
    public function testGetPluginPathReturnsNullForNonExistent(): void
    {
        $path = $this->discoverer->getPluginPath('vendor/non-existent');
        $this->assertNull($path);
    }

    /**
     * Test plugin.json takes priority over composer.json.
     */
    public function testPluginJsonTakesPriorityOverComposerJson(): void
    {
        $pluginPath = $this->tempDir . '/plugins/priority-test';
        mkdir($pluginPath, 0755, true);

        // Create both plugin.json and composer.json
        file_put_contents($pluginPath . '/plugin.json', json_encode([
            'name' => 'vendor/plugin-json-name',
            'version' => '2.0.0',
            'description' => 'From plugin.json',
        ]));

        file_put_contents($pluginPath . '/composer.json', json_encode([
            'name' => 'vendor/composer-json-name',
            'version' => '1.0.0',
            'description' => 'From composer.json',
        ]));

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(1, $plugins);
        $this->assertEquals('vendor/plugin-json-name', $plugins[0]['name']);
        $this->assertEquals('2.0.0', $plugins[0]['version']);
        $this->assertEquals('From plugin.json', $plugins[0]['description']);
    }

    /**
     * Test discovered plugins reflect installed status (via install.lock).
     */
    public function testDiscoveredPluginsReflectInstalledStatus(): void
    {
        $pluginPath = $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        // Create install.lock file to mark as installed
        file_put_contents($pluginPath . '/install.lock', json_encode([
            'installed_at' => '2024-01-01 00:00:00',
            'version' => '1.0.0',
        ]));

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(1, $plugins);
        $this->assertTrue($plugins[0]['installed']);
    }

    /**
     * Test discovered plugins reflect enabled status.
     */
    public function testDiscoveredPluginsReflectEnabledStatus(): void
    {
        $this->createPluginWithJson('test-plugin', [
            'name' => 'vendor/test-plugin',
            'version' => '1.0.0',
        ]);

        $this->configWriter->setPluginEnabled('vendor/test-plugin', true);

        $plugins = $this->discoverer->discoverLocalPlugins();

        $this->assertCount(1, $plugins);
        $this->assertTrue($plugins[0]['enabled']);
    }

    /**
     * Test getBasePath returns configured base path.
     */
    public function testGetBasePathReturnsConfiguredPath(): void
    {
        $this->assertEquals($this->tempDir, $this->discoverer->getBasePath());
    }

    /**
     * Test getPluginsDir returns configured plugins directory.
     */
    public function testGetPluginsDirReturnsConfiguredDir(): void
    {
        $this->assertEquals('plugins', $this->discoverer->getPluginsDir());
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
     * Create a plugin directory with plugin.json.
     */
    private function createPluginWithJson(string $name, array $config): string
    {
        $pluginPath = $this->tempDir . '/plugins/' . $name;
        mkdir($pluginPath, 0755, true);

        file_put_contents(
            $pluginPath . '/plugin.json',
            json_encode($config, JSON_PRETTY_PRINT)
        );

        return $pluginPath;
    }

    /**
     * Create a plugin directory with composer.json only (legacy).
     */
    private function createPluginWithComposer(string $name, array $composer): string
    {
        $pluginPath = $this->tempDir . '/plugins/' . $name;
        mkdir($pluginPath, 0755, true);

        file_put_contents(
            $pluginPath . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT)
        );

        return $pluginPath;
    }
}
