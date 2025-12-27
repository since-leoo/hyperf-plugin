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
 * 插件发现器接口 - 定义插件发现和信息获取操作.
 *
 * 负责发现项目中的可用插件，获取已安装插件列表，
 * 检查插件状态，以及获取插件的配置信息。
 */
interface PluginDiscovererInterface
{
    /**
     * 发现项目 plugins 目录中的本地插件.
     *
     * @return array 本地插件信息数组，每个元素包含 name, path, version, installed, enabled 等信息
     */
    public function discoverLocalPlugins(): array;

    /**
     * 获取已安装的插件列表.
     *
     * @return array 已安装插件的配置数组，键为包名
     */
    public function getInstalledPlugins(): array;

    /**
     * 检查插件是否已安装.
     *
     * @param string $packageName 插件包名
     * @return bool 是否已安装
     */
    public function isInstalled(string $packageName): bool;

    /**
     * 检查插件是否已启用.
     *
     * @param string $packageName 插件包名
     * @return bool 是否已启用
     */
    public function isEnabled(string $packageName): bool;

    /**
     * 获取插件的 ConfigProvider 类名.
     *
     * @param string $packageName 插件包名
     * @return null|string ConfigProvider 类名，不存在则返回 null
     */
    public function getPluginConfigProvider(string $packageName): ?string;

    /**
     * 获取插件的 Plugin 类名.
     *
     * @param string $packageName 插件包名
     * @return null|string Plugin 类名，不存在则返回 null
     */
    public function getPluginClass(string $packageName): ?string;

    /**
     * 获取插件的 plugin.json 配置.
     *
     * @param string $packageName 插件包名
     * @return array plugin.json 配置数组，不存在则返回空数组
     */
    public function getPluginJsonConfig(string $packageName): array;

    /**
     * 获取插件路径.
     *
     * @param string $packageName 插件包名
     * @return null|string 插件路径，不存在则返回 null
     */
    public function getPluginPath(string $packageName): ?string;

    /**
     * 注册插件的 PSR-4 自动加载.
     *
     * @param string $pluginPath 插件路径
     * @param array $pluginConfig 插件配置
     */
    public function registerPluginAutoload(string $pluginPath, array $pluginConfig): void;

    /**
     * 获取项目根路径.
     *
     * @return string 项目根路径
     */
    public function getBasePath(): string;
}
