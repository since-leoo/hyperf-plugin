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

namespace SinceLeoo\Plugin;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use RuntimeException;
use SinceLeoo\Plugin\Contract\MigrationRunnerInterface;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 迁移执行器 - 使用 Hyperf migrate 命令执行插件数据库迁移.
 *
 * 通过调用 Hyperf 的 migrate 命令来执行迁移，迁移记录由 Hyperf 自动管理。
 */
class MigrationRunner implements MigrationRunnerInterface
{
    /**
     * 插件发现器（用于获取插件路径）.
     */
    private PluginDiscovererInterface $discoverer;

    public function __construct(PluginDiscovererInterface $discoverer)
    {
        $this->discoverer = $discoverer;
    }

    public function migrate(string $packageName, string $migrationPath): array
    {
        if (! is_dir($migrationPath)) {
            return [];
        }

        $migrations = $this->discoverMigrations($migrationPath);
        if (empty($migrations)) {
            return [];
        }

        try {
            $container = ApplicationContext::getContainer();
            $application = $container->get(ApplicationInterface::class);

            // Hyperf migrate 命令的 --path 需要相对路径，使用 --realpath 支持绝对路径
            $input = new ArrayInput([
                'command' => 'migrate',
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);

            $output = new BufferedOutput();
            $application->setAutoExit(false);
            $exitCode = $application->run($input, $output);

            $outputContent = $output->fetch();

            if ($exitCode === 0) {
                return $migrations;
            }

            // 如果有输出，记录下来便于调试
            if (! empty($outputContent)) {
                throw new RuntimeException('Migration failed: ' . $outputContent);
            }

            return [];
        } catch (Throwable $e) {
            // 如果命令执行失败，抛出异常让上层处理
            throw $e;
        }
    }

    public function rollback(string $packageName, string $migrationPath): array
    {
        if (! is_dir($migrationPath)) {
            return [];
        }

        $migrations = $this->discoverMigrations($migrationPath);
        if (empty($migrations)) {
            return [];
        }

        try {
            $container = ApplicationContext::getContainer();
            $application = $container->get(ApplicationInterface::class);

            // 回滚该路径下的所有迁移，使用 --realpath 支持绝对路径
            $input = new ArrayInput([
                'command' => 'migrate:rollback',
                '--path' => $migrationPath,
                '--realpath' => true,
                '--step' => count($migrations), // 回滚所有
            ]);

            $output = new BufferedOutput();
            $application->setAutoExit(false);
            $exitCode = $application->run($input, $output);

            if ($exitCode === 0) {
                return $migrations;
            }

            return [];
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * 注意：使用 Hyperf migrate 命令时，迁移记录由 Hyperf 的 migrations 表管理
     */
    public function getExecutedMigrations(string $packageName): array
    {
        // Hyperf 自己管理迁移记录，这里返回空数组
        // 如果需要查询，可以从 migrations 表中查询
        return [];
    }

    public function getPendingMigrations(string $packageName, string $migrationPath): array
    {
        // Hyperf 的 migrate 命令会自动处理待执行的迁移
        return $this->discoverMigrations($migrationPath);
    }

    /**
     * 发现迁移目录中的所有迁移文件.
     *
     * @param string $migrationPath 迁移目录路径
     * @return array 迁移文件名列表（按文件名升序排列）
     */
    public function discoverMigrations(string $migrationPath): array
    {
        if (! is_dir($migrationPath)) {
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
}
