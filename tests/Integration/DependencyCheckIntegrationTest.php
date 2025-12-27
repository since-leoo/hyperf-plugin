<?php

declare(strict_types=1);

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
 * Integration tests for plugin dependency checking
 * 
 * Feature: hyperf-plugin-refactor
 * 
 * Tests dependency checking logic for installation, uninstallation, and enable/disable.
 * 
 * **Validates: Requirements 8.1, 8.2, 8.3**
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
        $this->migrationRunner = new MigrationRunner($this->configWriter);
        $this->seederRunner = new SeederRunner($this->configWriter);
        
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
     * Create a test plugin directory with plugin.json
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

    /**
     * Test checkDependencies returns missing dependencies
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
        $missing = $this->manager->checkDependencies($packageName);
        
        $this->assertCount(2, $missing);
        $this->assertContains($dependency1, $missing);
        $this->assertContains($dependency2, $missing);
    }

    /**
     * Test checkDependencies returns empty when all dependencies satisfied
     * 
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsEmptyWhenSatisfied(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $dependency = 'vendor/dependency-plugin';
        
        // Create and install dependency
        $this->createTestPlugin($dependency);
        $this->manager->install($dependency);
        
        // Create plugin with dependency
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
        ]);
        
        // Check dependencies (all installed)
        $missing = $this->manager->checkDependencies($packageName);
        
        $this->assertEmpty($missing);
    }

    /**
     * Test checkDependencies returns partial missing dependencies
     * 
     * **Validates: Requirements 8.1**
     */
    public function testCheckDependenciesReturnsPartialMissing(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $installedDep = 'vendor/installed-dep';
        $missingDep = 'vendor/missing-dep';
        
        // Create and install one dependency
        $this->createTestPlugin($installedDep);
        $this->manager->install($installedDep);
        
        // Create plugin with both dependencies
        $this->createTestPlugin($packageName, [
            'dependencies' => [$installedDep, $missingDep],
        ]);
        
        // Check dependencies
        $missing = $this->manager->checkDependencies($packageName);
        
        $this->assertCount(1, $missing);
        $this->assertContains($missingDep, $missing);
        $this->assertNotContains($installedDep, $missing);
    }

    /**
     * Test installation fails with missing dependencies
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
     * Test installation succeeds with satisfied dependencies
     * 
     * **Validates: Requirements 8.1**
     */
    public function testInstallationSucceedsWithSatisfiedDependencies(): void
    {
        $dependency = 'vendor/base-plugin';
        $packageName = 'vendor/dependent-plugin';
        
        // Create and install dependency first
        $this->createTestPlugin($dependency);
        $this->manager->install($dependency);
        
        // Create and install dependent plugin
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
        ]);
        
        $result = $this->manager->install($packageName);
        
        $this->assertTrue($result, 'Installation should succeed with satisfied dependencies');
        $this->assertTrue($this->discoverer->isInstalled($packageName));
    }

    /**
     * Test uninstallation blocked when other plugins depend on it
     * 
     * **Validates: Requirements 8.3**
     */
    public function testUninstallationBlockedWithDependentPlugins(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';
        
        // Create and install base plugin
        $this->createTestPlugin($basePlugin);
        $this->manager->install($basePlugin);
        
        // Create and install dependent plugin
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
        ]);
        $this->manager->install($dependentPlugin);
        
        // Try to uninstall base plugin
        $result = $this->manager->uninstall($basePlugin);
        
        $this->assertFalse($result, 'Uninstallation should be blocked when other plugins depend on it');
        $this->assertTrue($this->discoverer->isInstalled($basePlugin), 'Base plugin should still be installed');
    }

    /**
     * Test uninstallation allowed after dependent is uninstalled
     * 
     * **Validates: Requirements 8.3**
     */
    public function testUninstallationAllowedAfterDependentRemoved(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';
        
        // Create and install base plugin
        $this->createTestPlugin($basePlugin);
        $this->manager->install($basePlugin);
        
        // Create and install dependent plugin
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
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
     * Test disabling plugin warns about dependent plugins
     * 
     * **Validates: Requirements 8.2**
     */
    public function testDisablingPluginWithDependents(): void
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
        
        // Disable base plugin (should succeed with warning, not block)
        $result = $this->manager->disable($basePlugin);
        
        // Disabling should succeed (only warns, doesn't block)
        $this->assertTrue($result, 'Disabling should succeed (with warning)');
        $this->assertFalse($this->discoverer->isEnabled($basePlugin));
    }

    /**
     * Test enabling plugin with missing dependencies fails
     * 
     * **Validates: Requirements 8.2**
     */
    public function testEnablingPluginWithMissingDependenciesFails(): void
    {
        $packageName = 'vendor/dependent-plugin';
        $dependency = 'vendor/missing-dependency';
        
        // Create plugin with dependency
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
        ]);
        
        // Manually install without checking dependencies
        $this->configWriter->updatePluginConfig($packageName, [
            'version' => '1.0.0',
            'path' => $this->tempDir . '/plugins/' . str_replace('/', '-', $packageName),
        ]);
        
        // Try to enable
        $result = $this->manager->enable($packageName);
        
        $this->assertFalse($result, 'Enabling should fail with missing dependencies');
        $this->assertFalse($this->discoverer->isEnabled($packageName));
    }

    /**
     * Test enabling plugin with satisfied dependencies succeeds
     * 
     * **Validates: Requirements 8.2**
     */
    public function testEnablingPluginWithSatisfiedDependenciesSucceeds(): void
    {
        $dependency = 'vendor/base-plugin';
        $packageName = 'vendor/dependent-plugin';
        
        // Create and install dependency
        $this->createTestPlugin($dependency);
        $this->manager->install($dependency);
        
        // Create and install dependent plugin (disabled by default)
        $this->createTestPlugin($packageName, [
            'dependencies' => [$dependency],
            'enabled' => false,
        ]);
        $this->manager->install($packageName);
        
        // Enable dependent plugin
        $result = $this->manager->enable($packageName);
        
        $this->assertTrue($result, 'Enabling should succeed with satisfied dependencies');
        $this->assertTrue($this->discoverer->isEnabled($packageName));
    }

    /**
     * Test chain dependencies are checked
     * 
     * **Validates: Requirements 8.1**
     */
    public function testChainDependenciesChecked(): void
    {
        $pluginA = 'vendor/plugin-a';
        $pluginB = 'vendor/plugin-b';
        $pluginC = 'vendor/plugin-c';
        
        // Create plugin A (no dependencies)
        $this->createTestPlugin($pluginA);
        
        // Create plugin B (depends on A)
        $this->createTestPlugin($pluginB, [
            'dependencies' => [$pluginA],
        ]);
        
        // Create plugin C (depends on B)
        $this->createTestPlugin($pluginC, [
            'dependencies' => [$pluginB],
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
     * Test multiple plugins depending on same base
     * 
     * **Validates: Requirements 8.3**
     */
    public function testMultipleDependentsOnSameBase(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependent1 = 'vendor/dependent-one';
        $dependent2 = 'vendor/dependent-two';
        
        // Create and install base plugin
        $this->createTestPlugin($basePlugin);
        $this->manager->install($basePlugin);
        
        // Create and install two dependent plugins
        $this->createTestPlugin($dependent1, [
            'dependencies' => [$basePlugin],
        ]);
        $this->manager->install($dependent1);
        
        $this->createTestPlugin($dependent2, [
            'dependencies' => [$basePlugin],
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
     * Test plugin with no dependencies
     * 
     * **Validates: Requirements 8.1**
     */
    public function testPluginWithNoDependencies(): void
    {
        $packageName = 'vendor/standalone-plugin';
        
        // Create plugin with no dependencies
        $this->createTestPlugin($packageName, [
            'dependencies' => [],
        ]);
        
        // Check dependencies
        $missing = $this->manager->checkDependencies($packageName);
        $this->assertEmpty($missing);
        
        // Install should succeed
        $result = $this->manager->install($packageName);
        $this->assertTrue($result);
        
        // Uninstall should succeed
        $result = $this->manager->uninstall($packageName);
        $this->assertTrue($result);
    }

    /**
     * Test force uninstall bypasses dependency check
     * 
     * **Validates: Requirements 8.3**
     */
    public function testForceUninstallBypassesDependencyCheck(): void
    {
        $basePlugin = 'vendor/base-plugin';
        $dependentPlugin = 'vendor/dependent-plugin';
        
        // Create and install base plugin
        $this->createTestPlugin($basePlugin);
        $this->manager->install($basePlugin);
        
        // Create and install dependent plugin
        $this->createTestPlugin($dependentPlugin, [
            'dependencies' => [$basePlugin],
        ]);
        $this->manager->install($dependentPlugin);
        
        // Force uninstall base plugin
        $result = $this->manager->uninstall($basePlugin, true);
        
        $this->assertTrue($result, 'Force uninstall should bypass dependency check');
        $this->assertFalse($this->discoverer->isInstalled($basePlugin));
    }

    /**
     * Test checkDependencies for non-existent plugin
     */
    public function testCheckDependenciesForNonExistentPlugin(): void
    {
        $missing = $this->manager->checkDependencies('vendor/non-existent');
        
        $this->assertEmpty($missing, 'Should return empty for non-existent plugin');
    }
}
