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
 * 配置写入器接口 - 定义插件配置的读写操作.
 *
 * 负责读取全局插件配置（如插件目录路径等）。
 * 插件的启用状态由各插件的 plugin.json 管理。
 * 安装状态由 install.lock 文件管理。
 */
interface ConfigWriterInterface
{
    /**
     * 获取完整配置.
     *
     * @return array 完整的插件配置数组
     */
    public function getConfig(): array;
}
