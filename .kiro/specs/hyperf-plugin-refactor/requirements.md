# Requirements Document

## Introduction

本文档定义了 Hyperf 插件管理包的重构需求。该包用于管理 Hyperf 框架中的插件生命周期，包括插件的发现、安装、卸载、启用/禁用等功能。重构目标是提升代码质量、完善功能、增强可维护性和可测试性。

核心改进：通过 `plugin.json` 配置文件声明插件元数据，简化插件开发。

## Glossary

- **Plugin_Manager**: 插件管理器，负责插件的加载、注册和生命周期管理
- **Plugin_Discoverer**: 插件发现器，负责扫描和发现项目中的可用插件
- **Plugin_Config**: 插件配置，存储在 `config/autoload/plugins.php` 中的插件状态配置
- **Plugin_Interface**: 插件接口，所有插件必须实现的标准接口
- **Plugin_Repository**: 插件仓库，管理插件的存储和检索
- **Config_Writer**: 配置写入器，负责安全地写入配置文件
- **Plugin_Json**: 插件配置文件，位于插件根目录的 `plugin.json`，声明插件元数据
- **Migration_Runner**: 迁移执行器，负责执行插件定义的数据库迁移
- **Seeder_Runner**: 数据填充执行器，负责执行插件定义的数据填充

## Requirements

### Requirement 1: 插件接口标准化

**User Story:** As a 插件开发者, I want 有一个简洁的插件接口, so that 我可以快速开发插件而无需实现大量方法。

#### Acceptance Criteria

1. THE Plugin_Interface SHALL define only two lifecycle methods (install, uninstall)
2. THE Plugin_Interface SHALL define a getName method that reads from plugin.json
3. THE AbstractPlugin SHALL automatically load plugin.json configuration
4. THE AbstractPlugin SHALL automatically detect plugin root directory
5. WHEN a plugin does not implement Plugin_Interface, THEN THE Plugin_Manager SHALL skip loading that plugin and log a warning

### Requirement 2: Plugin.json 配置文件

**User Story:** As a 插件开发者, I want 通过配置文件声明插件元数据, so that 我不需要在代码中实现大量 getter 方法。

#### Acceptance Criteria

1. THE plugin.json SHALL support name, version, description, author fields for metadata
2. THE plugin.json SHALL support priority field for loading order (default: 0)
3. THE plugin.json SHALL support dependencies array for plugin dependencies
4. THE plugin.json SHALL support rollback_on_uninstall field (default: false)
5. THE plugin.json SHALL support enabled field for default enabled status (default: false)
6. WHEN plugin.json is missing required fields (name, version), THE System SHALL reject the plugin
7. THE System SHALL use convention-based directory structure for migrations (Database/Migrations) and seeders (Database/Seeders)

### Requirement 3: 插件配置管理

**User Story:** As a 项目管理员, I want 通过配置文件控制插件的启用状态, so that 我可以灵活管理插件而无需重新安装。

#### Acceptance Criteria

1. THE Plugin_Config SHALL store plugin enabled/disabled status separately from installation status
2. WHEN a plugin is installed, THE Plugin_Manager SHALL use the enabled value from plugin.json as default
3. WHEN a plugin is enabled via configuration, THE Plugin_Manager SHALL load and boot the plugin
4. WHEN a plugin is disabled via configuration, THE Plugin_Manager SHALL skip loading the plugin
5. THE Config_Writer SHALL generate valid PHP array syntax when writing configuration
6. THE Config_Writer SHALL preserve existing configuration entries when updating
7. WHEN configuration file does not exist, THE Config_Writer SHALL create it with default structure


### Requirement 4: 插件启用/禁用命令

**User Story:** As a 项目管理员, I want 通过命令行启用或禁用插件, so that 我可以快速切换插件状态。

#### Acceptance Criteria

1. WHEN running `plugin:enable {pluginName}`, THE System SHALL set the plugin status to enabled in configuration
2. WHEN running `plugin:disable {pluginName}`, THE System SHALL set the plugin status to disabled in configuration
3. WHEN enabling a non-installed plugin, THE System SHALL display an error message
4. WHEN disabling a non-installed plugin, THE System SHALL display an error message
5. WHEN enabling an already enabled plugin, THE System SHALL display an informational message
6. WHEN disabling an already disabled plugin, THE System SHALL display an informational message

### Requirement 5: 插件安装改进

**User Story:** As a 项目管理员, I want 安装插件时有更好的错误处理和反馈, so that 我可以了解安装过程中的问题。

#### Acceptance Criteria

1. WHEN installing a plugin, THE System SHALL validate the plugin's plugin.json structure
2. WHEN a plugin has missing dependencies, THE System SHALL display the missing dependencies list
3. WHEN installation fails, THE System SHALL rollback any partial changes
4. WHEN installation succeeds, THE System SHALL call the plugin's install hook if defined
5. THE System SHALL support installing plugins from both local path and remote repository
6. WHEN installing from remote, THE System SHALL validate the package exists before attempting installation

### Requirement 6: 插件卸载改进

**User Story:** As a 项目管理员, I want 卸载插件时能正确清理所有相关资源, so that 系统保持干净状态。

#### Acceptance Criteria

1. WHEN uninstalling a plugin, THE System SHALL call the plugin's uninstall hook if defined
2. WHEN uninstalling a plugin, THE System SHALL remove the plugin from configuration
3. WHEN uninstalling a plugin, THE System SHALL remove the local repository entry from composer.json
4. IF uninstallation fails, THEN THE System SHALL display the error and preserve the current state
5. WHEN uninstalling a non-installed plugin, THE System SHALL display an error message

### Requirement 7: 插件列表增强

**User Story:** As a 项目管理员, I want 查看更详细的插件信息, so that 我可以更好地管理插件。

#### Acceptance Criteria

1. THE plugin:list command SHALL display plugin name, version, status, description, and author from plugin.json
2. THE plugin:list command SHALL support filtering by status (installed, enabled, disabled, available)
3. THE plugin:list command SHALL support JSON output format for scripting
4. WHEN no plugins are found, THE System SHALL display an appropriate message

### Requirement 8: 插件依赖检查

**User Story:** As a 项目管理员, I want 系统检查插件之间的依赖关系, so that 我不会意外禁用被其他插件依赖的插件。

#### Acceptance Criteria

1. THE Plugin_Manager SHALL track plugin dependencies from plugin.json
2. WHEN disabling a plugin that other enabled plugins depend on, THE System SHALL display a warning with dependent plugins list
3. WHEN uninstalling a plugin that other installed plugins depend on, THE System SHALL prevent uninstallation unless forced
4. THE plugin:list command SHALL display plugin dependencies when using verbose mode

### Requirement 9: 插件加载顺序

**User Story:** As a 插件开发者, I want 控制插件的加载顺序, so that 我的插件可以依赖其他插件的功能。

#### Acceptance Criteria

1. THE plugin.json SHALL support priority configuration for each plugin
2. THE Plugin_Manager SHALL load plugins in priority order (higher priority first)
3. WHEN no priority is specified in plugin.json, THE Plugin_Manager SHALL use default priority of 0
4. WHEN two plugins have the same priority, THE Plugin_Manager SHALL load them in alphabetical order

### Requirement 10: 插件事件系统

**User Story:** As a 插件开发者, I want 在插件生命周期中触发事件, so that 其他组件可以响应插件状态变化。

#### Acceptance Criteria

1. THE Plugin_Manager SHALL dispatch PluginInstalled event after successful installation
2. THE Plugin_Manager SHALL dispatch PluginUninstalled event after successful uninstallation
3. THE Plugin_Manager SHALL dispatch PluginEnabled event after plugin is enabled
4. THE Plugin_Manager SHALL dispatch PluginDisabled event after plugin is disabled
5. THE Plugin_Manager SHALL dispatch PluginBooted event after plugin boot method is called
6. THE Plugin_Manager SHALL dispatch PluginMigrated event after migrations are executed
7. THE Plugin_Manager SHALL dispatch PluginSeeded event after seeder is executed

### Requirement 11: 配置发布

**User Story:** As a 项目管理员, I want 发布默认配置文件到项目中, so that 我可以自定义插件管理行为。

#### Acceptance Criteria

1. THE ConfigProvider SHALL register the publish configuration for plugins.php
2. WHEN running vendor:publish, THE System SHALL copy the default plugins.php to config/autoload/
3. THE published configuration SHALL include documentation comments explaining each option

### Requirement 12: 错误处理和日志

**User Story:** As a 项目管理员, I want 系统记录插件操作日志, so that 我可以排查问题。

#### Acceptance Criteria

1. THE Plugin_Manager SHALL log plugin loading errors with full stack trace
2. THE Plugin_Manager SHALL log plugin boot failures and continue loading other plugins
3. WHEN a plugin throws an exception during boot, THE System SHALL catch it and log the error
4. THE System SHALL use Hyperf's logger component for all logging operations


### Requirement 13: 插件数据库迁移

**User Story:** As a 插件开发者, I want 在约定目录放置迁移文件, so that 插件安装时可以自动创建所需的数据库表结构。

#### Acceptance Criteria

1. THE System SHALL automatically detect Database/Migrations directory in plugin root
2. WHEN a plugin is installed and has Database/Migrations directory, THE Migration_Runner SHALL execute all pending migrations
3. WHEN migration execution fails, THE System SHALL rollback the installation and display the error
4. THE Migration_Runner SHALL track which migrations have been executed per plugin
5. THE Plugin_Config SHALL store migration execution status for each plugin
6. WHEN a plugin has migrations, THE System SHALL execute them in filename order (ascending)

### Requirement 14: 插件数据填充

**User Story:** As a 插件开发者, I want 在约定目录放置填充器, so that 插件安装时可以自动填充初始数据。

#### Acceptance Criteria

1. THE System SHALL automatically detect Database/Seeders directory in plugin root
2. WHEN a plugin is installed and has Database/Seeders directory, THE Seeder_Runner SHALL execute all seeders after migrations
3. WHEN executing seeders, THE System SHALL regenerate proxy classes before execution to avoid class not found errors
4. WHEN seeder execution fails, THE System SHALL log the error but continue with installation (non-blocking)
5. THE Plugin_Config SHALL store seeder execution status for each plugin
6. THE System SHALL support running seeders independently via `plugin:seed {pluginName}` command

### Requirement 15: 插件卸载时的数据回滚

**User Story:** As a 项目管理员, I want 在 plugin.json 中配置卸载时是否回滚数据库变更, so that 我可以根据需要保留或清理插件数据。

#### Acceptance Criteria

1. THE plugin.json SHALL support rollback_on_uninstall option (default: false)
2. WHEN uninstalling a plugin with rollback_on_uninstall set to true, THE System SHALL rollback all plugin migrations
3. WHEN uninstalling a plugin with rollback_on_uninstall set to false, THE System SHALL preserve database tables
4. WHEN rollback fails, THE System SHALL display the error and ask user whether to force uninstall
5. THE plugin:uninstall command SHALL support a --rollback flag to override the plugin.json configuration
6. THE plugin:uninstall command SHALL support a --no-rollback flag to skip rollback regardless of configuration
7. WHEN rolling back migrations, THE System SHALL execute them in reverse filename order (descending)

### Requirement 16: 安装流程顺序

**User Story:** As a 项目管理员, I want 安装插件时按正确顺序执行各步骤, so that 插件能正确初始化。

#### Acceptance Criteria

1. WHEN installing a plugin, THE System SHALL execute steps in order: composer require → migrations → seeders → plugin install hook
2. WHEN any step fails before plugin install hook, THE System SHALL rollback all previous steps
3. THE plugin install hook SHALL be called after migrations and seeders complete successfully
