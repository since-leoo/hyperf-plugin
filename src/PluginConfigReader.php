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

use SinceLeoo\Plugin\Contract\PluginConfigReaderInterface;

/**
 * 插件配置读取器 - 实现 plugin.json 配置文件的读取和验证
 *
 * 负责读取和解析插件的 plugin.json 配置文件，验证必填字段，
 * 提供默认值支持，以及检测约定目录结构。
 */
class PluginConfigReader implements PluginConfigReaderInterface
{
    /**
     * 必填字段列表.
     */
    private const REQUIRED_FIELDS = ['name', 'version'];

    /**
     * 默认配置值
     */
    private const DEFAULT_VALUES = [
        'description' => '',
        'author' => '',
        'namespace' => '',
        'priority' => 0,
        'dependencies' => [],
        'rollback_on_uninstall' => false,
        'enabled' => false,
        'configProvider' => null,
    ];

    /**
     * 约定的迁移目录相对路径.
     */
    private const MIGRATIONS_PATH = 'Database/Migrations';

    /**
     * 约定的填充器目录相对路径.
     */
    private const SEEDERS_PATH = 'Database/Seeders';

    public function read(string $pluginPath): array
    {
        $jsonPath = rtrim($pluginPath, '/') . '/plugin.json';

        if (! file_exists($jsonPath)) {
            return [];
        }

        $content = file_get_contents($jsonPath);

        if ($content === false) {
            return [];
        }

        $config = json_decode($content, true);
        if (! is_array($config)) {
            return [];
        }

        return $config;
    }

    public function validate(array $config): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($config[$field]) || $config[$field] === '') {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // 验证字段类型
        if (isset($config['name']) && ! is_string($config['name'])) {
            $errors[] = "Field 'name' must be a string";
        }

        if (isset($config['version']) && ! is_string($config['version'])) {
            $errors[] = "Field 'version' must be a string";
        }

        if (isset($config['description']) && ! is_string($config['description'])) {
            $errors[] = "Field 'description' must be a string";
        }

        if (isset($config['author']) && ! is_string($config['author'])) {
            $errors[] = "Field 'author' must be a string";
        }

        if (isset($config['priority']) && ! is_int($config['priority'])) {
            $errors[] = "Field 'priority' must be an integer";
        }

        if (isset($config['dependencies']) && ! is_array($config['dependencies'])) {
            $errors[] = "Field 'dependencies' must be an array";
        }

        if (isset($config['rollback_on_uninstall']) && ! is_bool($config['rollback_on_uninstall'])) {
            $errors[] = "Field 'rollback_on_uninstall' must be a boolean";
        }

        if (isset($config['enabled']) && ! is_bool($config['enabled'])) {
            $errors[] = "Field 'enabled' must be a boolean";
        }

        return $errors;
    }

    public function get(array $config, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }

        if ($default !== null) {
            return $default;
        }

        return self::DEFAULT_VALUES[$key] ?? null;
    }

    public function hasMigrations(string $pluginPath): bool
    {
        $migrationPath = $this->getMigrationPath($pluginPath);
        return $migrationPath !== null;
    }

    public function getMigrationPath(string $pluginPath): ?string
    {
        $path = rtrim($pluginPath, '/') . '/' . self::MIGRATIONS_PATH;

        if (is_dir($path)) {
            return $path;
        }

        return null;
    }

    public function hasSeeders(string $pluginPath): bool
    {
        $seederPath = $this->getSeederPath($pluginPath);
        return $seederPath !== null;
    }

    public function getSeederPath(string $pluginPath): ?string
    {
        $path = rtrim($pluginPath, '/') . '/' . self::SEEDERS_PATH;

        if (is_dir($path)) {
            return $path;
        }

        return null;
    }

    /**
     * 读取并应用默认值的完整配置.
     *
     * @param string $pluginPath 插件根目录路径
     * @return array 包含默认值的完整配置数组
     */
    public function readWithDefaults(string $pluginPath): array
    {
        $config = $this->read($pluginPath);

        if (empty($config)) {
            return [];
        }

        return array_merge(self::DEFAULT_VALUES, $config);
    }
}
