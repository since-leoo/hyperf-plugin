<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\MigrationRunnerInterface;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;

/**
 * 迁移执行器 - 实现插件数据库迁移的执行操作
 * 
 * 负责执行插件的数据库迁移，包括执行待执行迁移、回滚已执行迁移、
 * 获取迁移状态等操作。迁移按文件名升序执行，回滚按文件名降序执行。
 */
class MigrationRunner implements MigrationRunnerInterface
{
    /**
     * 配置写入器（用于存储迁移执行状态）
     */
    private ConfigWriterInterface $configWriter;

    public function __construct(ConfigWriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    /**
     * {@inheritdoc}
     */
    public function migrate(string $packageName, string $migrationPath): array
    {
        $pendingMigrations = $this->getPendingMigrations($packageName, $migrationPath);
        
        if (empty($pendingMigrations)) {
            return [];
        }

        $executedMigrations = [];

        foreach ($pendingMigrations as $migrationFile) {
            $this->executeMigration($migrationPath, $migrationFile);
            $executedMigrations[] = $migrationFile;
        }

        // 更新配置中的已执行迁移列表
        $this->updateExecutedMigrations($packageName, $executedMigrations);

        return $executedMigrations;
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(string $packageName, string $migrationPath): array
    {
        $executedMigrations = $this->getExecutedMigrations($packageName);
        
        if (empty($executedMigrations)) {
            return [];
        }

        // 按文件名降序排列进行回滚
        $migrationsToRollback = $executedMigrations;
        rsort($migrationsToRollback, SORT_STRING);

        $rolledBackMigrations = [];

        foreach ($migrationsToRollback as $migrationFile) {
            $this->executeRollback($migrationPath, $migrationFile);
            $rolledBackMigrations[] = $migrationFile;
        }

        // 清除配置中的已执行迁移列表
        $this->clearExecutedMigrations($packageName);

        return $rolledBackMigrations;
    }

    /**
     * {@inheritdoc}
     */
    public function getExecutedMigrations(string $packageName): array
    {
        $config = $this->configWriter->getConfig();
        
        return $config['installed'][$packageName]['migrations_executed'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingMigrations(string $packageName, string $migrationPath): array
    {
        $allMigrations = $this->discoverMigrations($migrationPath);
        $executedMigrations = $this->getExecutedMigrations($packageName);

        $pendingMigrations = array_diff($allMigrations, $executedMigrations);
        
        // 按文件名升序排列
        sort($pendingMigrations, SORT_STRING);

        return array_values($pendingMigrations);
    }

    /**
     * 发现迁移目录中的所有迁移文件
     * 
     * @param string $migrationPath 迁移目录路径
     * @return array 迁移文件名列表（按文件名升序排列）
     */
    public function discoverMigrations(string $migrationPath): array
    {
        if (!is_dir($migrationPath)) {
            return [];
        }

        $files = scandir($migrationPath);
        if ($files === false) {
            return [];
        }

        $migrations = array_filter($files, function (string $file) use ($migrationPath): bool {
            // 只包含 .php 文件
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                return false;
            }
            
            // 排除目录
            $fullPath = rtrim($migrationPath, '/') . '/' . $file;
            return is_file($fullPath);
        });

        // 按文件名升序排列
        sort($migrations, SORT_STRING);

        return array_values($migrations);
    }

    /**
     * 执行单个迁移文件
     * 
     * @param string $migrationPath 迁移目录路径
     * @param string $migrationFile 迁移文件名
     */
    private function executeMigration(string $migrationPath, string $migrationFile): void
    {
        $fullPath = rtrim($migrationPath, '/') . '/' . $migrationFile;
        
        if (!file_exists($fullPath)) {
            return;
        }

        $migration = require $fullPath;
        
        if (is_object($migration) && method_exists($migration, 'up')) {
            $migration->up();
        }
    }

    /**
     * 执行单个迁移文件的回滚
     * 
     * @param string $migrationPath 迁移目录路径
     * @param string $migrationFile 迁移文件名
     */
    private function executeRollback(string $migrationPath, string $migrationFile): void
    {
        $fullPath = rtrim($migrationPath, '/') . '/' . $migrationFile;
        
        if (!file_exists($fullPath)) {
            return;
        }

        $migration = require $fullPath;
        
        if (is_object($migration) && method_exists($migration, 'down')) {
            $migration->down();
        }
    }

    /**
     * 更新配置中的已执行迁移列表
     * 
     * @param string $packageName 插件包名
     * @param array $newMigrations 新执行的迁移列表
     */
    private function updateExecutedMigrations(string $packageName, array $newMigrations): void
    {
        $config = $this->configWriter->getConfig();
        
        $existingMigrations = $config['installed'][$packageName]['migrations_executed'] ?? [];
        $allMigrations = array_unique(array_merge($existingMigrations, $newMigrations));
        sort($allMigrations, SORT_STRING);

        $pluginConfig = $config['installed'][$packageName] ?? [];
        $pluginConfig['migrations_executed'] = array_values($allMigrations);
        
        $this->configWriter->updatePluginConfig($packageName, $pluginConfig);
    }

    /**
     * 清除配置中的已执行迁移列表
     * 
     * @param string $packageName 插件包名
     */
    private function clearExecutedMigrations(string $packageName): void
    {
        $config = $this->configWriter->getConfig();
        
        $pluginConfig = $config['installed'][$packageName] ?? [];
        $pluginConfig['migrations_executed'] = [];
        
        $this->configWriter->updatePluginConfig($packageName, $pluginConfig);
    }
}
