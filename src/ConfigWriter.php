<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin;

use SinceLeoo\Plugin\Contract\ConfigWriterInterface;

/**
 * 配置写入器 - 实现插件配置文件的读写操作
 * 
 * 负责安全地读写插件配置文件，确保生成有效的 PHP 数组语法，
 * 并在更新时保留现有配置。
 */
class ConfigWriter implements ConfigWriterInterface
{
    /**
     * 配置文件路径
     */
    private string $configPath;

    /**
     * 默认配置结构
     */
    private array $defaultConfig = [
        'plugins_path' => 'plugins',
        'installed' => [],
        'enabled' => [],
        'priorities' => [],
    ];

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
    }

    /**
     * 获取默认配置文件路径
     */
    private function getDefaultConfigPath(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH . '/config/autoload/plugins.php';
        }
        return getcwd() . '/config/autoload/plugins.php';
    }

    /**
     * {@inheritdoc}
     */
    public function updatePluginConfig(string $packageName, array $config): void
    {
        $currentConfig = $this->getConfig();
        $currentConfig['installed'][$packageName] = $config;
        $this->writeConfig($currentConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function removePluginConfig(string $packageName): void
    {
        $currentConfig = $this->getConfig();
        
        unset($currentConfig['installed'][$packageName]);
        unset($currentConfig['enabled'][$packageName]);
        unset($currentConfig['priorities'][$packageName]);
        
        $this->writeConfig($currentConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function setPluginEnabled(string $packageName, bool $enabled): void
    {
        $currentConfig = $this->getConfig();
        $currentConfig['enabled'][$packageName] = $enabled;
        $this->writeConfig($currentConfig);
    }


    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        if (!file_exists($this->configPath)) {
            return $this->defaultConfig;
        }

        $config = include $this->configPath;
        
        if (!is_array($config)) {
            return $this->defaultConfig;
        }

        return array_merge($this->defaultConfig, $config);
    }

    /**
     * 写入配置到文件
     * 
     * @param array $config 配置数组
     */
    private function writeConfig(array $config): void
    {
        $this->ensureDirectoryExists();
        
        $content = "<?php\n\nreturn " . $this->exportArray($config, 0) . ";\n";
        
        file_put_contents($this->configPath, $content);
    }

    /**
     * 确保配置目录存在
     */
    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * 将数组导出为有效的 PHP 数组语法字符串
     * 
     * @param array $array 要导出的数组
     * @param int $indent 缩进级别
     * @return string PHP 数组语法字符串
     */
    private function exportArray(array $array, int $indent): string
    {
        if (empty($array)) {
            return '[]';
        }

        $isAssociative = $this->isAssociativeArray($array);
        $indentStr = str_repeat('    ', $indent);
        $innerIndentStr = str_repeat('    ', $indent + 1);
        
        $lines = ["["];
        
        foreach ($array as $key => $value) {
            $keyPart = $isAssociative ? $this->exportValue($key) . ' => ' : '';
            $valuePart = $this->exportValue($value, $indent + 1);
            $lines[] = $innerIndentStr . $keyPart . $valuePart . ',';
        }
        
        $lines[] = $indentStr . ']';
        
        return implode("\n", $lines);
    }

    /**
     * 将值导出为有效的 PHP 语法字符串
     * 
     * @param mixed $value 要导出的值
     * @param int $indent 缩进级别
     * @return string PHP 语法字符串
     */
    private function exportValue(mixed $value, int $indent = 0): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $indent);
        }
        
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_null($value)) {
            return 'null';
        }
        
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        
        return var_export($value, true);
    }

    /**
     * 检查数组是否为关联数组
     * 
     * @param array $array 要检查的数组
     * @return bool 是否为关联数组
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * 获取配置文件路径
     * 
     * @return string 配置文件路径
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }
}
