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
 * 迁移执行器接口 - 定义插件数据库迁移的执行操作.
 *
 * 负责执行插件的数据库迁移，包括执行待执行迁移、回滚已执行迁移、
 * 获取迁移状态等操作。迁移按文件名升序执行，回滚按文件名降序执行。
 */
interface MigrationRunnerInterface
{
    /**
     * 执行插件的所有待执行迁移.
     *
     * 按文件名升序执行迁移文件，并记录已执行的迁移。
     *
     * @param string $packageName 插件包名
     * @param string $migrationPath 迁移文件目录路径
     * @return array 已执行的迁移文件列表
     */
    public function migrate(string $packageName, string $migrationPath): array;

    /**
     * 回滚插件的所有迁移.
     *
     * 按文件名降序回滚迁移文件。
     *
     * @param string $packageName 插件包名
     * @param string $migrationPath 迁移文件目录路径
     * @return array 已回滚的迁移文件列表
     */
    public function rollback(string $packageName, string $migrationPath): array;

    /**
     * 获取插件已执行的迁移列表.
     *
     * @param string $packageName 插件包名
     * @return array 已执行的迁移文件名列表
     */
    public function getExecutedMigrations(string $packageName): array;

    /**
     * 获取插件待执行的迁移列表.
     *
     * @param string $packageName 插件包名
     * @param string $migrationPath 迁移文件目录路径
     * @return array 待执行的迁移文件名列表
     */
    public function getPendingMigrations(string $packageName, string $migrationPath): array;
}
