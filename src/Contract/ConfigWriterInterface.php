<?php

declare(strict_types=1);

namespace SinceLeoo\Plugin\Contract;

/**
 * 配置写入器接口 - 定义插件配置文件的读写操作
 * 
 * 负责安全地读写插件配置文件，确保生成有效的 PHP 数组语法，
 * 并在更新时保留现有配置。
 */
interface ConfigWriterInterface
{
    /**
     * 更新插件配置
     * 
     * @param string $packageName 插件包名
     * @param array $config 插件配置数组
     */
    public function updatePluginConfig(string $packageName, array $config): void;

    /**
     * 移除插件配置
     * 
     * @param string $packageName 插件包名
     */
    public function removePluginConfig(string $packageName): void;

    /**
     * 设置插件启用状态
     * 
     * @param string $packageName 插件包名
     * @param bool $enabled 是否启用
     */
    public function setPluginEnabled(string $packageName, bool $enabled): void;

    /**
     * 获取完整配置
     * 
     * @return array 完整的插件配置数组
     */
    public function getConfig(): array;
}
