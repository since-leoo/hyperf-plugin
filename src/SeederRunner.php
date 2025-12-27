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

use RuntimeException;
use SinceLeoo\Plugin\Contract\PluginDiscovererInterface;
use SinceLeoo\Plugin\Contract\SeederRunnerInterface;
use Throwable;

/**
 * 填充器执行器 - 直接执行插件数据填充.
 *
 * 由于插件的 Seeder 类不在 Composer autoload 中，无法使用 Hyperf 的 db:seed 命令，
 * 因此直接 require 文件并实例化执行。
 */
class SeederRunner implements SeederRunnerInterface
{
    private PluginDiscovererInterface $discoverer;

    public function __construct(PluginDiscovererInterface $discoverer)
    {
        $this->discoverer = $discoverer;
    }

    public function seed(string $packageName, string $seederPath, bool $regenerateProxy = true): bool
    {
        if (! is_dir($seederPath)) {
            return true;
        }

        $seeders = $this->discoverSeeders($seederPath);
        if (empty($seeders)) {
            return true;
        }

        $success = true;

        foreach ($seeders as $seederFile) {
            try {
                $this->executeSeeder($seederPath, $seederFile);
            } catch (Throwable $e) {
                // 记录异常但不阻塞安装
                error_log("Seeder failed [{$seederFile}]: " . $e->getMessage());
                $success = false;
            }
        }

        return $success;
    }

    public function hasSeeded(string $packageName): bool
    {
        $lockData = $this->readLockFile($packageName);
        return $lockData['seeder_executed'] ?? false;
    }

    public function regenerateProxyClasses(): void
    {
        // 插件的 Seeder 不需要代理类
    }

    public function discoverSeeders(string $seederPath): array
    {
        if (! is_dir($seederPath)) {
            return [];
        }

        $files = scandir($seederPath);
        if ($files === false) {
            return [];
        }

        $seeders = array_filter($files, function (string $file) use ($seederPath): bool {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                return false;
            }

            $fullPath = rtrim($seederPath, '/') . '/' . $file;
            return is_file($fullPath);
        });

        sort($seeders, SORT_STRING);

        return array_values($seeders);
    }

    /**
     * 执行单个填充器文件.
     */
    private function executeSeeder(string $seederPath, string $seederFile): void
    {
        $fullPath = rtrim($seederPath, '/') . '/' . $seederFile;

        if (! file_exists($fullPath)) {
            return;
        }

        $className = $this->extractClassName($fullPath);

        if ($className === null) {
            return;
        }

        if (! class_exists($className)) {
            require_once $fullPath;
        }

        if (! class_exists($className)) {
            throw new RuntimeException("Seeder class not found: {$className}");
        }

        $seeder = new $className();

        if (method_exists($seeder, 'run')) {
            $seeder->run();
        }
    }

    /**
     * 从 PHP 文件中提取完整类名（包含命名空间）.
     */
    private function extractClassName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = trim($matches[1]);
        }

        if (empty($class)) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    private function readLockFile(string $packageName): array
    {
        $pluginPath = $this->discoverer->getPluginPath($packageName);
        if ($pluginPath === null) {
            return [];
        }

        $lockFile = $pluginPath . '/install.lock';
        if (! file_exists($lockFile)) {
            return [];
        }

        $content = file_get_contents($lockFile);
        return json_decode($content, true) ?? [];
    }
}
