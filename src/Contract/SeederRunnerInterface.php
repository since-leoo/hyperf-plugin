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
 * 填充器执行器接口 - 定义插件数据填充的执行操作.
 *
 * 负责执行插件的数据填充，包括执行填充器、检查填充状态、
 * 重新生成代理类等操作。填充器在迁移完成后执行。
 */
interface SeederRunnerInterface
{
    /**
     * 执行插件填充器目录中的所有填充器.
     *
     * @param string $packageName 插件包名
     * @param string $seederPath 填充器目录路径
     * @param bool $regenerateProxy 是否在执行前重新生成代理类
     * @return bool 执行是否成功
     */
    public function seed(string $packageName, string $seederPath, bool $regenerateProxy = true): bool;

    /**
     * 检查填充器是否已执行.
     *
     * @param string $packageName 插件包名
     * @return bool 是否已执行填充器
     */
    public function hasSeeded(string $packageName): bool;

    /**
     * 重新生成代理类（避免 composer dump -o 后代理类被删除）.
     */
    public function regenerateProxyClasses(): void;

    /**
     * 获取填充器目录中的所有填充器类.
     *
     * @param string $seederPath 填充器目录路径
     * @return array 填充器类名列表
     */
    public function discoverSeeders(string $seederPath): array;
}
