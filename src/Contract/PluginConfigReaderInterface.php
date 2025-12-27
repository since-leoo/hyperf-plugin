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

namespace SinceLeoo\Plugin\Contract;

/**
 * 插件配置读取器接口 - 定义 plugin.json 配置文件的读取和验证操作.
 *
 * 负责读取和解析插件的 plugin.json 配置文件，验证必填字段，
 * 提供默认值支持，以及检测约定目录结构。
 */
interface PluginConfigReaderInterface
{
    /**
     * 读取插件的 plugin.json 配置.
     *
     * @param string $pluginPath 插件根目录路径
     * @return array 配置数组，如果文件不存在或无效则返回空数组
     */
    public function read(string $pluginPath): array;

    /**
     * 验证 plugin.json 配置是否有效.
     *
     * @param array $config 配置数组
     * @return array 验证错误列表，空数组表示验证通过
     */
    public function validate(array $config): array;

    /**
     * 获取配置项，支持默认值
     *
     * @param array $config 配置数组
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed 配置值或默认值
     */
    public function get(array $config, string $key, mixed $default = null): mixed;

    /**
     * 检测插件是否有迁移目录.
     *
     * @param string $pluginPath 插件根目录路径
     * @return bool 是否存在 Database/Migrations 目录
     */
    public function hasMigrations(string $pluginPath): bool;

    /**
     * 获取迁移目录路径.
     *
     * @param string $pluginPath 插件根目录路径
     * @return null|string 迁移目录完整路径，不存在则返回 null
     */
    public function getMigrationPath(string $pluginPath): ?string;

    /**
     * 检测插件是否有填充器目录.
     *
     * @param string $pluginPath 插件根目录路径
     * @return bool 是否存在 Database/Seeders 目录
     */
    public function hasSeeders(string $pluginPath): bool;

    /**
     * 获取填充器目录路径.
     *
     * @param string $pluginPath 插件根目录路径
     * @return null|string 填充器目录完整路径，不存在则返回 null
     */
    public function getSeederPath(string $pluginPath): ?string;
}
