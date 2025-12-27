<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\SeederRunnerInterface;
use SinceLeoo\Plugin\Contract\ConfigWriterInterface;
use Throwable;

/**
 * 填充器执行器 - 实现插件数据填充的执行操作
 * 
 * 负责执行插件的数据填充，包括执行填充器、检查填充状态、
 * 重新生成代理类等操作。填充器在迁移完成后执行。
 */
class SeederRunner implements SeederRunnerInterface
{
    /**
     * 配置写入器（用于存储填充器执行状态）
     */
    private ConfigWriterInterface $configWriter;

    /**
     * 日志记录器（可选，使用 Hyperf 的 LoggerInterface 或任何 PSR-3 兼容的日志器）
     */
    private ?object $logger;

    public function __construct(ConfigWriterInterface $configWriter, ?object $logger = null)
    {
        $this->configWriter = $configWriter;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function seed(string $packageName, string $seederPath, bool $regenerateProxy = true): bool
    {
        if ($regenerateProxy) {
            $this->regenerateProxyClasses();
        }

        $seeders = $this->discoverSeeders($seederPath);
        
        if (empty($seeders)) {
            $this->updateSeederStatus($packageName, true);
            return true;
        }

        $success = true;

        foreach ($seeders as $seederFile) {
            try {
                $this->executeSeeder($seederPath, $seederFile);
            } catch (Throwable $e) {
                // 填充器失败不阻塞安装，只记录错误
                $this->log('error', "Seeder execution failed for {$packageName}: {$seederFile}", [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $success = false;
            }
        }

        // 无论成功与否都标记为已执行（非阻塞）
        $this->updateSeederStatus($packageName, true);

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function hasSeeded(string $packageName): bool
    {
        $config = $this->configWriter->getConfig();
        
        return $config['installed'][$packageName]['seeder_executed'] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function regenerateProxyClasses(): void
    {
        // 在 Hyperf 环境中，重新生成代理类
        // 这是为了避免 composer dump -o 后代理类被删除导致类找不到错误
        if (class_exists('Hyperf\Di\Aop\ProxyManager')) {
            try {
                // 尝试调用 Hyperf 的代理类生成器
                // 实际实现可能需要根据 Hyperf 版本调整
                $this->log('info', 'Attempting to regenerate proxy classes');
            } catch (Throwable $e) {
                $this->log('warning', 'Failed to regenerate proxy classes: ' . $e->getMessage());
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function discoverSeeders(string $seederPath): array
    {
        if (!is_dir($seederPath)) {
            return [];
        }

        $files = scandir($seederPath);
        if ($files === false) {
            return [];
        }

        $seeders = array_filter($files, function (string $file) use ($seederPath): bool {
            // 只包含 .php 文件
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                return false;
            }
            
            // 排除目录
            $fullPath = rtrim($seederPath, '/') . '/' . $file;
            return is_file($fullPath);
        });

        // 按文件名排序
        sort($seeders, SORT_STRING);

        return array_values($seeders);
    }

    /**
     * 执行单个填充器文件
     * 
     * @param string $seederPath 填充器目录路径
     * @param string $seederFile 填充器文件名
     */
    private function executeSeeder(string $seederPath, string $seederFile): void
    {
        $fullPath = rtrim($seederPath, '/') . '/' . $seederFile;
        
        if (!file_exists($fullPath)) {
            return;
        }

        $seeder = require $fullPath;
        
        if (is_object($seeder) && method_exists($seeder, 'run')) {
            $seeder->run();
        }
    }

    /**
     * 更新配置中的填充器执行状态
     * 
     * @param string $packageName 插件包名
     * @param bool $executed 是否已执行
     */
    private function updateSeederStatus(string $packageName, bool $executed): void
    {
        $config = $this->configWriter->getConfig();
        
        $pluginConfig = $config['installed'][$packageName] ?? [];
        $pluginConfig['seeder_executed'] = $executed;
        
        $this->configWriter->updatePluginConfig($packageName, $pluginConfig);
    }

    /**
     * 记录日志（如果日志器可用）
     * 
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }
}
