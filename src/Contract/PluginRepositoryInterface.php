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
 * 插件仓库接口 - 定义插件实例的存储和检索操作.
 *
 * 负责管理插件实例的注册、获取、检索等操作。
 * 插件元数据（名称、优先级等）通过 plugin.json 管理，
 * 仓库只负责存储运行时的插件实例。
 */
interface PluginRepositoryInterface
{
    /**
     * 注册插件实例.
     *
     * @param string $packageName 插件包名
     * @param PluginInterface $plugin 插件实例
     */
    public function register(string $packageName, PluginInterface $plugin): void;

    /**
     * 获取指定名称的插件实例.
     *
     * @param string $packageName 插件包名
     * @return null|PluginInterface 插件实例，不存在则返回 null
     */
    public function get(string $packageName): ?PluginInterface;

    /**
     * 检查插件是否已注册.
     *
     * @param string $packageName 插件包名
     * @return bool 是否已注册
     */
    public function has(string $packageName): bool;

    /**
     * 获取所有已注册的插件.
     *
     * @return array<string, PluginInterface> 插件包名 => 插件实例
     */
    public function all(): array;

    /**
     * 获取所有已启用的插件.
     *
     * @return array<string, PluginInterface> 已启用的插件
     */
    public function getEnabled(): array;
}
