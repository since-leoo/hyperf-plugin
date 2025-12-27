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
use SinceLeoo\Plugin\Contract\PluginInterface;
use SinceLeoo\Plugin\PluginRepository;

/**
 * Property-based tests for PluginRepository.
 *
 * Feature: hyperf-plugin-refactor
 *
 * These tests verify universal properties that should hold for all valid inputs.
 * @internal
 * @coversNothing
 */
class PluginRepositoryPropertyTest extends TestCase
{
    private string $tempDir;

    private string $configPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/plugin_repo_test_' . uniqid();
        mkdir($this->tempDir . '/config/autoload', 0755, true);
        $this->configPath = $this->tempDir . '/config/autoload/plugins.php';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Property 13: Priority-Based Loading Order.
     *
     * *For any* set of enabled plugins with different priorities (from plugin.json),
     * the Plugin_Manager SHALL load them in descending priority order.
     *
     * **Validates: Requirements 9.2, 9.3, 9.4**
     *
     * @dataProvider priorityBasedLoadingOrderProvider
     */
    public function testPriorityBasedLoadingOrder(array $pluginData): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $repository = new PluginRepository($configWriter);

        // Register all plugins
        foreach ($pluginData as $data) {
            $plugin = $this->createMockPlugin($data['name'], $data['priority']);
            $repository->register($plugin);
        }

        // Get plugins by priority
        $sortedPlugins = $repository->getByPriority();

        // Verify the order
        $previousPriority = PHP_INT_MAX;
        $previousName = '';

        foreach ($sortedPlugins as $plugin) {
            $currentPriority = $plugin->getPriority();
            $currentName = $plugin->getName();

            // Priority should be descending (higher priority first)
            $this->assertLessThanOrEqual(
                $previousPriority,
                $currentPriority,
                'Plugins should be sorted by priority in descending order'
            );

            // If same priority, should be alphabetically ordered
            if ($currentPriority === $previousPriority && $previousName !== '') {
                $this->assertGreaterThanOrEqual(
                    0,
                    strcmp($currentName, $previousName),
                    'Plugins with same priority should be sorted alphabetically'
                );
            }

            $previousPriority = $currentPriority;
            $previousName = $currentName;
        }

        // Verify all plugins are present
        $this->assertCount(count($pluginData), $sortedPlugins);
    }

    /**
     * Data provider for priority-based loading order test - generates 100 test cases.
     */
    public static function priorityBasedLoadingOrderProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            // Generate 2-10 plugins with random priorities
            $numPlugins = rand(2, 10);
            $pluginData = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                $pluginData[] = [
                    'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $i . '-' . $j,
                    'priority' => rand(-100, 100),
                ];
            }

            $testCases["iteration_{$i}"] = [$pluginData];
        }

        return $testCases;
    }

    /**
     * Test that default priority (0) is used when not specified.
     *
     * **Validates: Requirements 9.3**
     *
     * @dataProvider defaultPriorityProvider
     */
    public function testDefaultPriorityIsZero(array $pluginData): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $repository = new PluginRepository($configWriter);

        // Create plugins with explicit priorities and one with default (0)
        $pluginsWithHighPriority = [];
        $pluginsWithLowPriority = [];
        $pluginsWithDefaultPriority = [];

        foreach ($pluginData as $data) {
            $plugin = $this->createMockPlugin($data['name'], $data['priority']);
            $repository->register($plugin);

            if ($data['priority'] > 0) {
                $pluginsWithHighPriority[] = $data['name'];
            } elseif ($data['priority'] < 0) {
                $pluginsWithLowPriority[] = $data['name'];
            } else {
                $pluginsWithDefaultPriority[] = $data['name'];
            }
        }

        $sortedPlugins = $repository->getByPriority();
        $sortedNames = array_map(fn ($p) => $p->getName(), $sortedPlugins);

        // Verify high priority plugins come before default priority plugins
        foreach ($pluginsWithHighPriority as $highPriorityName) {
            $highPriorityIndex = array_search($highPriorityName, $sortedNames);
            foreach ($pluginsWithDefaultPriority as $defaultPriorityName) {
                $defaultPriorityIndex = array_search($defaultPriorityName, $sortedNames);
                $this->assertLessThan(
                    $defaultPriorityIndex,
                    $highPriorityIndex,
                    'High priority plugin should come before default priority plugin'
                );
            }
        }

        // Verify default priority plugins come before low priority plugins
        foreach ($pluginsWithDefaultPriority as $defaultPriorityName) {
            $defaultPriorityIndex = array_search($defaultPriorityName, $sortedNames);
            foreach ($pluginsWithLowPriority as $lowPriorityName) {
                $lowPriorityIndex = array_search($lowPriorityName, $sortedNames);
                $this->assertLessThan(
                    $lowPriorityIndex,
                    $defaultPriorityIndex,
                    'Default priority plugin should come before low priority plugin'
                );
            }
        }
    }

    /**
     * Data provider for default priority test - generates 100 test cases.
     */
    public static function defaultPriorityProvider(): array
    {
        $testCases = [];
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];

        for ($i = 0; $i < 100; ++$i) {
            $pluginData = [];

            // Always include at least one plugin with each priority type
            $pluginData[] = [
                'name' => $vendors[array_rand($vendors)] . '/high-priority-' . $i,
                'priority' => rand(1, 100),
            ];
            $pluginData[] = [
                'name' => $vendors[array_rand($vendors)] . '/default-priority-' . $i,
                'priority' => 0, // Default priority
            ];
            $pluginData[] = [
                'name' => $vendors[array_rand($vendors)] . '/low-priority-' . $i,
                'priority' => rand(-100, -1),
            ];

            // Add some random plugins
            $numExtra = rand(0, 5);
            for ($j = 0; $j < $numExtra; ++$j) {
                $pluginData[] = [
                    'name' => $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-extra-' . $i . '-' . $j,
                    'priority' => rand(-100, 100),
                ];
            }

            $testCases["iteration_{$i}"] = [$pluginData];
        }

        return $testCases;
    }

    /**
     * Test that same priority plugins are sorted alphabetically.
     *
     * **Validates: Requirements 9.4**
     *
     * @dataProvider samePriorityAlphabeticalProvider
     */
    public function testSamePriorityPluginsAreSortedAlphabetically(array $pluginNames, int $priority): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $repository = new PluginRepository($configWriter);

        // Register all plugins with the same priority
        foreach ($pluginNames as $name) {
            $plugin = $this->createMockPlugin($name, $priority);
            $repository->register($plugin);
        }

        $sortedPlugins = $repository->getByPriority();
        $sortedNames = array_map(fn ($p) => $p->getName(), $sortedPlugins);

        // Sort the original names alphabetically for comparison
        $expectedNames = $pluginNames;
        sort($expectedNames);

        $this->assertEquals(
            $expectedNames,
            $sortedNames,
            'Plugins with same priority should be sorted alphabetically'
        );
    }

    /**
     * Data provider for same priority alphabetical test - generates 100 test cases.
     */
    public static function samePriorityAlphabeticalProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 100; ++$i) {
            // Generate 2-8 unique plugin names
            $numPlugins = rand(2, 8);
            $pluginNames = [];

            for ($j = 0; $j < $numPlugins; ++$j) {
                // Use letters to make alphabetical ordering clear
                $letter = chr(ord('a') + rand(0, 25));
                $pluginNames[] = $letter . '-vendor/plugin-' . $i . '-' . $j;
            }

            // Ensure unique names
            $pluginNames = array_unique($pluginNames);

            $priority = rand(-100, 100);

            $testCases["iteration_{$i}"] = [$pluginNames, $priority];
        }

        return $testCases;
    }

    /**
     * Test basic repository operations.
     */
    public function testBasicRepositoryOperations(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $repository = new PluginRepository($configWriter);

        // Test empty repository
        $this->assertEmpty($repository->all());
        $this->assertFalse($repository->has('vendor/test-plugin'));
        $this->assertNull($repository->get('vendor/test-plugin'));
        $this->assertEquals(0, $repository->count());

        // Register a plugin
        $plugin = $this->createMockPlugin('vendor/test-plugin', 10);
        $repository->register($plugin);

        // Test after registration
        $this->assertTrue($repository->has('vendor/test-plugin'));
        $this->assertNotNull($repository->get('vendor/test-plugin'));
        $this->assertEquals(1, $repository->count());
        $this->assertCount(1, $repository->all());

        // Test clear
        $repository->clear();
        $this->assertEmpty($repository->all());
        $this->assertEquals(0, $repository->count());
    }

    /**
     * Test getEnabled returns only enabled plugins.
     */
    public function testGetEnabledReturnsOnlyEnabledPlugins(): void
    {
        $configWriter = new ConfigWriter($this->configPath);
        $repository = new PluginRepository($configWriter);

        // Register plugins
        $plugin1 = $this->createMockPlugin('vendor/plugin-1', 10);
        $plugin2 = $this->createMockPlugin('vendor/plugin-2', 20);
        $plugin3 = $this->createMockPlugin('vendor/plugin-3', 30);

        $repository->register($plugin1);
        $repository->register($plugin2);
        $repository->register($plugin3);

        // Enable only plugin-1 and plugin-3
        $configWriter->setPluginEnabled('vendor/plugin-1', true);
        $configWriter->setPluginEnabled('vendor/plugin-2', false);
        $configWriter->setPluginEnabled('vendor/plugin-3', true);

        $enabledPlugins = $repository->getEnabled();

        $this->assertCount(2, $enabledPlugins);

        $enabledNames = array_map(fn ($p) => $p->getName(), $enabledPlugins);
        $this->assertContains('vendor/plugin-1', $enabledNames);
        $this->assertContains('vendor/plugin-3', $enabledNames);
        $this->assertNotContains('vendor/plugin-2', $enabledNames);
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
     * Create a mock plugin with specified properties.
     */
    private function createMockPlugin(string $name, int $priority): PluginInterface
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getName')->willReturn($name);
        $plugin->method('getPriority')->willReturn($priority);
        $plugin->method('getVersion')->willReturn('1.0.0');
        $plugin->method('getDescription')->willReturn('Test plugin');
        $plugin->method('getAuthor')->willReturn('Test Author');
        $plugin->method('getDependencies')->willReturn([]);

        return $plugin;
    }

    /**
     * Generate random plugin name.
     */
    private function generateRandomPluginName(int $index): string
    {
        $vendors = ['vendor', 'acme', 'example', 'test', 'demo'];
        $names = ['plugin', 'module', 'extension', 'addon', 'component'];
        return $vendors[array_rand($vendors)] . '/' . $names[array_rand($names)] . '-' . $index;
    }
}
