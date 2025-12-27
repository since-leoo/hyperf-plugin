# Implementation Plan: Hyperf Plugin Refactor

## Overview

本实现计划将设计文档转化为可执行的编码任务。采用增量开发方式，从核心接口开始，逐步构建完整的插件管理系统。每个任务都引用具体的需求条款，确保完整覆盖。

## Tasks

- [x] 1. 创建核心接口和基类
  - [x] 1.1 创建 PluginInterface 接口
    - 在 `src/Contract/PluginInterface.php` 定义简化的插件标准接口
    - 包含 getName, install, uninstall 方法
    - _Requirements: 1.1, 1.2_

  - [x] 1.2 创建 AbstractPlugin 抽象基类
    - 在 `src/Contract/AbstractPlugin.php` 提供默认实现
    - 自动检测插件目录并加载 plugin.json
    - 实现 getName 从 plugin.json 读取
    - 提供 install, uninstall 空默认实现
    - _Requirements: 1.3, 1.4_

- [x] 2. 实现配置管理组件
  - [x] 2.1 创建 ConfigWriterInterface 接口
    - 在 `src/Contract/ConfigWriterInterface.php` 定义配置写入接口
    - _Requirements: 3.5, 3.6_

  - [x] 2.2 实现 ConfigWriter 类
    - 在 `src/ConfigWriter.php` 实现配置文件读写
    - 实现 updatePluginConfig, removePluginConfig, setPluginEnabled, getConfig 方法
    - 确保生成有效的 PHP 数组语法
    - 确保更新时保留现有配置
    - _Requirements: 3.5, 3.6, 3.7_

  - [x] 2.3 编写 ConfigWriter 属性测试
    - **Property 1: Configuration Round-Trip Consistency**
    - **Property 2: Configuration Preservation on Update**
    - **Validates: Requirements 3.5, 3.6**

- [x] 3. 实现 Plugin.json 配置读取组件
  - [x] 3.1 创建 PluginConfigReaderInterface 接口
    - 在 `src/Contract/PluginConfigReaderInterface.php` 定义配置读取接口
    - 包含 read, validate, get, hasMigrations, getMigrationPath, hasSeeders, getSeederPath 方法
    - _Requirements: 2.1, 2.7_

  - [x] 3.2 实现 PluginConfigReader 类
    - 在 `src/PluginConfigReader.php` 实现 plugin.json 读取和验证
    - 实现约定目录检测 (Database/Migrations, Database/Seeders)
    - 验证必填字段 (name, version)
    - 提供默认值支持
    - _Requirements: 2.1-2.7_

  - [x] 3.3 编写 PluginConfigReader 属性测试
    - **Property 5: Plugin.json Configuration Accuracy**
    - **Validates: Requirements 2.1-2.7**


- [x] 4. 实现插件发现组件
  - [x] 4.1 创建 PluginDiscovererInterface 接口
    - 在 `src/Contract/PluginDiscovererInterface.php` 定义发现器接口
    - _Requirements: 5.1_

  - [x] 4.2 重构 PluginDiscoverer 类
    - 重构 `src/PluginDiscoverer.php` 实现新接口
    - 实现 discoverLocalPlugins, getInstalledPlugins, isInstalled, isEnabled 方法
    - 实现 getPluginConfigProvider, getPluginClass, getPluginJsonConfig 方法
    - 从 plugin.json 读取插件信息
    - _Requirements: 5.1, 7.1_

  - [x] 4.3 编写 PluginDiscoverer 单元测试
    - 测试插件发现逻辑
    - 测试 plugin.json 解析
    - _Requirements: 5.1_

- [x] 5. 实现插件仓库组件
  - [x] 5.1 创建 PluginRepositoryInterface 接口
    - 在 `src/Contract/PluginRepositoryInterface.php` 定义仓库接口
    - _Requirements: 9.1_

  - [x] 5.2 实现 PluginRepository 类
    - 在 `src/PluginRepository.php` 实现插件实例管理
    - 实现 register, get, has, all, getEnabled, getByPriority 方法
    - 实现优先级排序逻辑（从 plugin.json 读取 priority）
    - _Requirements: 9.1, 9.2, 9.3, 9.4_

  - [x] 5.3 编写 PluginRepository 属性测试
    - **Property 13: Priority-Based Loading Order**
    - **Validates: Requirements 9.2, 9.3, 9.4**

- [x] 6. Checkpoint - 核心组件验证
  - 确保所有测试通过，如有问题请询问用户

- [x] 7. 实现迁移执行组件
  - [x] 7.1 创建 MigrationRunnerInterface 接口
    - 在 `src/Contract/MigrationRunnerInterface.php` 定义迁移执行器接口
    - _Requirements: 13.1_

  - [x] 7.2 实现 MigrationRunner 类
    - 在 `src/MigrationRunner.php` 实现迁移执行
    - 实现 migrate, rollback, getExecutedMigrations, getPendingMigrations 方法
    - 按文件名升序执行迁移
    - 按文件名降序回滚迁移
    - _Requirements: 13.2, 13.4, 13.5, 13.6_

  - [x] 7.3 编写 MigrationRunner 属性测试
    - **Property 6: Migration Execution on Install**
    - **Property 7: Migration Execution Order**
    - **Validates: Requirements 13.2, 13.6**

- [x] 8. 实现填充器执行组件
  - [x] 8.1 创建 SeederRunnerInterface 接口
    - 在 `src/Contract/SeederRunnerInterface.php` 定义填充器执行器接口
    - _Requirements: 14.1_

  - [x] 8.2 实现 SeederRunner 类
    - 在 `src/SeederRunner.php` 实现填充器执行
    - 实现 seed, hasSeeded, regenerateProxyClasses, discoverSeeders 方法
    - 执行前重新生成代理类避免类找不到错误
    - _Requirements: 14.2, 14.3, 14.4, 14.5_

  - [x] 8.3 编写 SeederRunner 属性测试
    - **Property 9: Seeder Execution After Migrations**
    - **Property 10: Seeder Failure Non-Blocking**
    - **Validates: Requirements 14.2, 14.4**

- [x] 9. Checkpoint - 迁移和填充组件验证
  - 确保所有测试通过，如有问题请询问用户


- [x] 10. 实现事件系统
  - [x] 10.1 创建事件基类和具体事件类
    - 在 `src/Event/PluginEvent.php` 创建基类
    - 创建 PluginInstalledEvent, PluginUninstalledEvent, PluginEnabledEvent, PluginDisabledEvent
    - 创建 PluginMigratedEvent, PluginMigrationRolledBackEvent, PluginSeededEvent
    - _Requirements: 10.1-10.7_

- [x] 11. 重构 PluginManager
  - [x] 11.1 创建 PluginManagerInterface 接口
    - 在 `src/Contract/PluginManagerInterface.php` 定义管理器接口
    - _Requirements: 3.3, 3.4_

  - [x] 11.2 重构 PluginManager 核心功能
    - 重构 `src/PluginManager.php` 实现新接口
    - 注入 PluginDiscoverer, PluginRepository, ConfigWriter, PluginConfigReader, MigrationRunner, SeederRunner, EventDispatcher, Logger
    - 实现 bootPlugins 方法，按优先级加载已启用插件
    - 实现错误隔离，单个插件失败不影响其他插件
    - _Requirements: 3.3, 3.4, 9.2, 12.1, 12.2, 12.3_

  - [x] 11.3 实现 PluginManager 安装功能
    - 实现 install 方法
    - 验证 plugin.json 结构
    - 执行顺序: composer require → migrations → seeders → plugin install hook
    - 失败时回滚
    - 触发 PluginInstalledEvent, PluginMigratedEvent, PluginSeededEvent
    - _Requirements: 5.1, 5.3, 5.4, 10.1, 10.6, 10.7, 16.1, 16.2, 16.3_

  - [x] 11.4 实现 PluginManager 卸载功能
    - 实现 uninstall 方法
    - 检查依赖关系
    - 调用插件 uninstall 钩子
    - 根据 rollback_on_uninstall 配置决定是否回滚迁移
    - 支持 --rollback/--no-rollback 参数覆盖
    - 清理配置和 composer.json
    - 触发 PluginUninstalledEvent, PluginMigrationRolledBackEvent
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 8.3, 10.2, 15.1-15.7_

  - [x] 11.5 实现 PluginManager 启用/禁用功能
    - 实现 enable, disable 方法
    - 检查依赖关系（禁用时）
    - 触发相应事件
    - _Requirements: 4.1, 4.2, 8.2, 10.3, 10.4_

  - [x] 11.6 实现依赖检查功能
    - 实现 checkDependencies 方法
    - 从 plugin.json 读取依赖信息
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 11.7 编写 PluginManager 属性测试
    - **Property 3: Plugin Loading Respects Enabled Status**
    - **Property 4: New Installations Default to Disabled**
    - **Property 8: Migration Rollback on Failure**
    - **Property 11: Migration Rollback on Uninstall**
    - **Property 12: Database Preservation on Uninstall**
    - **Property 14: Error Isolation During Boot**
    - **Validates: Requirements 3.2, 3.3, 3.4, 12.1-12.3, 15.2, 15.3**

- [x] 12. Checkpoint - PluginManager 验证
  - 确保所有测试通过，如有问题请询问用户


- [x] 13. 实现命令行工具
  - [ ] 13.1 重构 PluginInstallCommand
    - 重构 `src/Command/PluginInstallCommand.php`
    - 使用依赖注入获取 PluginManager
    - 改进错误处理和输出
    - _Requirements: 5.1, 5.2, 5.5, 5.6_

  - [x] 13.2 重构 PluginUninstallCommand
    - 重构 `src/Command/PluginUninstallCommand.php`
    - 使用依赖注入获取 PluginManager
    - 添加 --rollback 和 --no-rollback 选项
    - 添加依赖检查提示
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 15.5, 15.6_

  - [x] 13.3 创建 PluginEnableCommand
    - 创建 `src/Command/PluginEnableCommand.php`
    - 实现 plugin:enable {pluginName} 命令
    - 处理各种边界情况
    - _Requirements: 4.1, 4.3, 4.5_

  - [x] 13.4 创建 PluginDisableCommand
    - 创建 `src/Command/PluginDisableCommand.php`
    - 实现 plugin:disable {pluginName} 命令
    - 显示依赖警告
    - _Requirements: 4.2, 4.4, 4.6, 8.2_

  - [x] 13.5 重构 PluginListCommand
    - 重构 `src/Command/PluginListCommand.php`
    - 从 plugin.json 读取插件信息显示
    - 添加 --status 过滤选项
    - 添加 --json 输出选项
    - 添加 -v 详细模式显示依赖
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 8.4_

  - [x] 13.6 创建 PluginSeedCommand
    - 创建 `src/Command/PluginSeedCommand.php`
    - 实现 plugin:seed {pluginName} 命令
    - 支持独立执行插件填充器
    - _Requirements: 14.6_

  - [x] 13.7 编写命令属性测试
    - 测试命令参数解析和输出格式
    - **Validates: Requirements 4.1, 4.2, 7.1, 7.2, 7.3**

- [x] 14. 更新配置和引导
  - [x] 14.1 更新 ConfigProvider
    - 更新 `src/ConfigProvider.php`
    - 注册所有新命令
    - 注册依赖注入绑定
    - 配置发布文件
    - _Requirements: 11.1, 11.2_

  - [x] 14.2 更新发布配置文件
    - 更新 `publish/plugins.php`
    - 添加详细的文档注释
    - 包含所有配置选项
    - _Requirements: 11.3_

  - [x] 14.3 更新 PluginBootListener
    - 更新 `src/Listener/PluginBootListener.php`
    - 使用依赖注入
    - _Requirements: 3.3, 3.4_

- [x] 15. Checkpoint - 集成验证
  - 确保所有测试通过，如有问题请询问用户

- [x] 16. 编写集成测试
  - [x] 16.1 编写安装流程集成测试
    - 测试完整安装流程（包含迁移和填充）
    - 测试安装失败回滚
    - **Validates: Requirements 5.3, 16.1, 16.2**

  - [x] 16.2 编写卸载流程集成测试
    - 测试卸载时回滚迁移
    - 测试卸载时保留数据库
    - **Validates: Requirements 15.2, 15.3**

  - [x] 16.3 编写依赖检查集成测试
    - 测试依赖检查逻辑
    - **Validates: Requirements 8.1, 8.2, 8.3**

- [x] 17. Final Checkpoint - 完整验证
  - 确保所有测试通过，如有问题请询问用户

## Notes

- 所有任务均为必需，确保完整的测试覆盖
- 每个任务都引用了具体的需求条款以确保可追溯性
- Checkpoint 任务用于阶段性验证
- 属性测试验证通用正确性属性
- 单元测试验证具体示例和边界情况
- 插件开发者只需继承 AbstractPlugin 并实现 install/uninstall 方法
- 迁移和填充器使用约定目录结构，无需额外配置
