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
 * Integration tests for plugin dependency checking.
 *
 * Feature: hyperf-plugin-refactor
 *
 * Tests dependency checking logic for installation, uninstallation, and enable/disable.
 *
 * **Validates: Requirements 8.1, 8.2, 8.3**
 * @internal
 * @coversNothing
 */
class DependencyCheckIntegrationTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir() . '/plugin_dependency_integration_test_' . uniqid();
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
     * Test checkDependencies returns missing dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsMissingDependencies(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $dependency1 = 'vendor/dependency-one';
        $dependency2 = 'vendor/dependency-two';

        // Create plugin with dependencies
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency1, $dependency2],
        ]);

        // Check dependencies (none installed)
        $result = $this->manager->checkDependencies($packageName);

        $this->assertCount(2, $result['missing']);
        $this->assertContains($dependency1, $result['missing']);
        $this->assertContains($dependency2, $result['missing']);
        $this->assertEmpty($result['disabled']);
    }

    /**
     * Test checkDependencies returns empty when all dependencies satisfied.
     *
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsEmptyWhenSatisfied(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $dependency = 'vendor/dependency-plugin';

        // Create and install dependency (enabled)
        $this->createTestPlugin($dependency, ['enabled' => true]);
        $this->manager->install($dependency);

        // Create plugin with dependency
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
        ]);

        // Check dependencies (all installed and enabled)
        $result = $this->manager->checkDependencies($packageName);

        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['disabled']);
    }

    /**
     * Test checkDependencies returns partial missing dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsPartialMissing(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $installedDep = 'vendor/installed-dep';
        $missingDep = 'vendor/missing-dep';

        // Create and install one dependency (enabled)
        $this->createTestPlugin($installedDep, ['enabled' => true]);
        $this->manager->install($installedDep);

        // Create plugin with both dependencies
        $this->createTestPlugin($packageName, [
            'dependencies' => [$installedDep, $missingDep],
        ]);

        // Check dependencies
        $result = $this->manager->checkDependencies($packageName);

        $this->assertCount(1, $result['missing']);
        $this->assertContains($missingDep, $result['missing']);
        $this->assertNotContains($installedDep, $result['missing']);
        $this->assertEmpty($result['disabled']);
    }

    /**
     * Test checkDependencies returns disabled dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsDisabledDependencies(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $dependency = 'vendor/disabled-dep';

        // Create and install dependency (disabled)
        $this->createTestPlugin($dependency, ['enabled' => false]);
        $this->manager->install($dependency);

        // Create plugin with dependency
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
        ]);

        // Check dependencies
        $result = $this->manager->checkDependencies($packageName);

        $this->assertEmpty($result['missing']);
        $this->assertCount(1, $result['disabled']);
        $this->assertContains($dependency, $result['disabled']);
    }

    /**
     * Test installation fails with missing dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testInstallationFailsWithMissingDependencies(): void
    {
        $packageName = 'vendor/dependent-plugin';

        // Create plugin with missing dependency
        $this->createTestPlugin($packageName, [
            'dependencies' => ['vendor/non-existent-plugin'],
        ]);

        // Try to install
        $result = $this->manager->install($packageName);

        $this->assertFalse($result, 'Installation should fail with missing dependencies');
        $this->assertFalse($this->discoverer->isInstalled($packageName));
    }

    /**
     * Test installation succeeds with satisfied dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testInstallationSucceedsWithSatisfiedDependencies(): void
    {
        $dependency = 'vendor/base-plugin';
        $packageName = 'vendor/dependent-plugin';

        // Create and install dependency first (enabled)
        $this->createTestPlugin($dependency, ['enabled' => true]);
        $this->manager->install($dependency);

        // Create and install dependent plugin (enabled)
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
            'enabled' => true,
        ]);

        $result = $this->manager->install($packageName);

        $this->assertTrue($result, 'Installation should succeed with satisfied dependencies');
        $this->assertTrue($this->discoverer->isInstalled($packageName));
    }

    /**
     * Test uninstallation blocked when other plugins depend on it.
     *
     * **Validates: Requirements 8.3**
     */
    public function testUninstallationBlockedWithDependentPlugins(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';

        // Create and install base plugin (enabled)
        $this->createTestPlugin($basePlugin, ['enabled' => true]);
        $this->manager->install($basePlugin);

        // Create and install dependent plugin (enabled)
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
            'enabled' => true,
        ]);
        $this->manager->install($dependentPlugin);

        // Try to uninstall base plugin
        $result = $this->manager->uninstall($basePlugin);

        $this->assertFalse($result, 'Uninstallation should be blocked when other plugins depend on it');
        $this->assertTrue($this->discoverer->isInstalled($basePlugin), 'Base plugin should still be installed');
    }

    /**
     * Test uninstallation allowed after dependent is uninstalled.
     *
     * **Validates: Requirements 8.3**
     */
    public function testUninstallationAllowedAfterDependentRemoved(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';

        // Create and install base plugin (enabled)
        $this->createTestPlugin($basePlugin, ['enabled' => true]);
        $this->manager->install($basePlugin);

        // Create and install dependent plugin (enabled)
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
            'enabled' => true,
        ]);
        $this->manager->install($dependentPlugin);

        // Uninstall dependent first
        $this->manager->uninstall($dependentPlugin);

        // Now uninstall base plugin should succeed
        $result = $this->manager->uninstall($basePlugin);

        $this->assertTrue($result, 'Uninstallation should succeed after dependent is removed');
        $this->assertFalse($this->discoverer->isInstalled($basePlugin));
    }

    /**
     * Test chain dependencies are checked.
     *
     * **Validates: Requirements 8.1**
     */
    public function testChainDependenciesChecked(): void
    {
        $pluginA = 'vendor/plugin-a';
        $pluginB = 'vendor/plugin-b';
        $pluginC = 'vendor/plugin-c';

        // Create plugin A (no dependencies, enabled)
        $this->createTestPlugin($pluginA, ['enabled' => true]);

        // Create plugin B (depends on A, enabled)
        $this->createTestPlugin($pluginB, [
            'dependencies' => [$pluginA],
            'enabled' => true,
        ]);

        // Create plugin C (depends on B, enabled)
        $this->createTestPlugin($pluginC, [
            'dependencies' => [$pluginB],
            'enabled' => true,
        ]);

        // Try to install C without A and B
        $result = $this->manager->install($pluginC);
        $this->assertFalse($result, 'Should fail - B is not installed');

        // Install A
        $this->manager->install($pluginA);

        // Try to install C (still missing B)
        $result = $this->manager->install($pluginC);
        $this->assertFalse($result, 'Should fail - B is still not installed');

        // Install B
        $this->manager->install($pluginB);

        // Now install C should succeed
        $result = $this->manager->install($pluginC);
        $this->assertTrue($result, 'Should succeed - all dependencies installed');
    }

    /**
     * Test multiple plugins depending on same base.
     *
     * **Validates: Requirements 8.3**
     */
    public function testMultipleDependentsOnSameBase(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependent1 = 'vendor/dependent-one';
        $dependent2 = 'vendor/dependent-two';

        // Create and install base plugin (enabled)
        $this->createTestPlugin($basePlugin, ['enabled' => true]);
        $this->manager->install($basePlugin);

        // Create and install two dependent plugins (enabled)
        $this->createTestPlugin($dependent1, [
            'dependencies' => [$basePlugin],
            'enabled' => true,
        ]);
        $this->manager->install($dependent1);

        $this->createTestPlugin($dependent2, [
            'dependencies' => [$basePlugin],
            'enabled' => true,
        ]);
        $this->manager->install($dependent2);

        // Try to uninstall base plugin (should fail)
        $result = $this->manager->uninstall($basePlugin);
        $this->assertFalse($result);

        // Uninstall one dependent
        $this->manager->uninstall($dependent1);

        // Still can't uninstall base (dependent2 still depends on it)
        $result = $this->manager->uninstall($basePlugin);
        $this->assertFalse($result);

        // Uninstall second dependent
        $this->manager->uninstall($dependent2);

        // Now can uninstall base
        $result = $this->manager->uninstall($basePlugin);
        $this->assertTrue($result);
    }

    /**
     * Test plugin with no dependencies.
     *
     * **Validates: Requirements 8.1**
     */
    public function testPluginWithNoDependencies(): void
    {
        $packageName = 'vendor/standalone-plugin';

        // Create plugin with no dependencies (enabled)
        $this->createTestPlugin($packageName, [
            'dependencies' => [],
            'enabled' => true,
        ]);

        // Check dependencies
        $result = $this->manager->checkDependencies($packageName);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['disabled']);

        // Install should succeed
        $result = $this->manager->install($packageName);
        $this->assertTrue($result);

        // Uninstall should succeed
        $result = $this->manager->uninstall($packageName);
        $this->assertTrue($result);
    }

    /**
     * Test force uninstall bypasses dependency check.
     *
     * **Validates: Requirements 8.3**
     */
    public function testForceUninstallBypassesDependencyCheck(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';

        // Create and install base plugin (enabled)
        $this->createTestPlugin($basePlugin, ['enabled' => true]);
        $this->manager->install($basePlugin);

        // Create and install dependent plugin (enabled)
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
            'enabled' => true,
        ]);
        $this->manager->install($dependentPlugin);

        // Force uninstall base plugin
        $result = $this->manager->uninstall($basePlugin, true);

        $this->assertTrue($result, 'Force uninstall should bypass dependency check');
        $this->assertFalse($this->discoverer->isInstalled($basePlugin));
    }

    /**
     * Test checkDependencies for non-existent plugin.
     */
    public function testCheckDependenciesForNonExistentPlugin(): void
    {
        $result = $this->manager->checkDependencies('vendor/non-existent');

        $this->assertEmpty($result['missing'], 'Should return empty for non-existent plugin');
        $this->assertEmpty($result['disabled'], 'Should return empty for non-existent plugin');
    }

    /**
     * Test installation fails when dependency is installed but not enabled.
     *
     * **Validates: Requirements 8.1**
     */
    public function testInstallationFailsWhenDependencyNotEnabled(): void
    {
        $dependency = 'vendor/base-plugin';
        $packageName = 'vendor/dependent-plugin';

        // Create and install dependency (disabled)
        $this->createTestPlugin($dependency, ['enabled' => false]);
        $this->manager->install($dependency);

        // Create dependent plugin (enabled)
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
            'enabled' => true,
        ]);

        // Try to install - should fail because dependency is not enabled
        $result = $this->manager->install($packageName);

        $this->assertFalse($result, 'Installation should fail when dependency is not enabled');
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
     * Create a test plugin directory with plugin.json.
     */
    private function createTestPlugin(string $name, array $config = []): string
    {
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

        return $pluginDir;
    }
}
