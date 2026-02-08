# Hyperf Plugin Manager

一个简洁、易用的 Hyperf 插件管理系统，支持插件的完整生命周期管理。

## 特性

- 🚀 **极简开发** - 只需 `plugin.json` 即可创建插件
- 📦 **配置驱动** - 所有元数据在 `plugin.json` 中定义，无需编写 getter 方法
- 🔄 **完整生命周期** - 安装、卸载、启用、禁用、启动
- 🗃️ **数据库支持** - 自动执行迁移和数据填充
- 🔗 **依赖管理** - 自动检查和安装插件间依赖关系
- 📚 **Composer 依赖** - 支持声明第三方 Composer 包依赖
- ⚡ **优先级加载** - 按优先级顺序加载插件
- 🛡️ **错误隔离** - 单个插件失败不影响其他插件

## 安装

```bash
composer require since-leoo/hyperf-plugin

# 发布配置文件
php bin/hyperf.php vendor:publish since-leoo/hyperf-plugin
```

## 快速开始

### 1. 生成插件骨架

```bash
php bin/hyperf.php plugin:make my-plugin
```

### 2. 配置插件
在 `bin/hyperf.php 文件中` 

`require BASE_PATH . '/vendor/autoload.php';` 这行代码下 添加以下代码

```php
 \SinceLeoo\Plugin\PluginBootstrap::init();
```

编辑 `plugins/my-plugin/plugin.json`，设置 `enabled: true` 启用插件。

### 3. 安装插件

```bash
php bin/hyperf.php plugin:install vendor/my-plugin
```

## 插件结构

```
plugins/my-plugin/
├── src/
│   ├── Plugin.php           # 插件主类（必需）
│   ├── ConfigProvider.php   # 配置提供者（可选）
│   └── Command/             # 命令目录（可选）
├── Database/
│   ├── Migrations/          # 迁移文件（可选，自动检测）
│   └── Seeders/             # 填充器（可选，自动检测）
├── publish/                 # 可发布文件（可选）
├── install.lock             # 安装状态文件（自动生成）
└── plugin.json              # 插件配置（必需）
```

## plugin.json 配置

```json
{
    "name": "vendor/my-plugin",
    "version": "1.0.0",
    "description": "插件描述",
    "author": "作者",
    "namespace": "Vendor\\MyPlugin",
    "priority": 0,
    "enabled": true,
    "dependencies": ["vendor/other-plugin"],
    "composer_require": {
        "guzzlehttp/guzzle": "^7.0"
    },
    "rollback_on_uninstall": false,
    "configProvider": "Vendor\\MyPlugin\\ConfigProvider"
}
```

| 字段 | 必填 | 默认值 | 说明 |
|------|------|--------|------|
| `name` | ✅ | - | 插件包名（格式：vendor/name） |
| `version` | ✅ | - | 版本号 |
| `namespace` | ❌ | - | 命名空间（用于自动加载插件类） |
| `priority` | ❌ | 0 | 加载优先级（越大越先加载） |
| `enabled` | ❌ | false | 是否启用插件 |
| `dependencies` | ❌ | [] | 依赖的其他本地插件 |
| `composer_require` | ❌ | {} | 依赖的 Composer 包 |
| `rollback_on_uninstall` | ❌ | false | 卸载时是否回滚迁移 |
| `configProvider` | ❌ | - | ConfigProvider 类名 |

## Plugin 主类

```php
<?php

namespace Vendor\MyPlugin;

use SinceLeoo\Plugin\Contract\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    // 安装时调用（迁移和填充之后）
    public function install(): void
    {
        // 发布配置文件等操作
    }

    // 卸载时调用（回滚迁移之前）
    public function uninstall(): void
    {
        // 清理发布的文件等操作
    }

    // 每次应用启动时调用（仅 enabled: true 的插件）
    public function boot(): void {}
}
```

> 所有方法都有空的默认实现，只需覆盖你需要的方法。

## 运行机制

### 安装流程

1. **验证** → 检查 `plugin.json` 必填字段（name, version）
2. **依赖检查** → 检查依赖插件是否已安装且已启用，自动安装缺失的本地插件依赖
3. **Composer 依赖** → 安装 `composer_require` 中声明的第三方包
4. **执行迁移** → 使用 Hyperf migrate 命令执行 `Database/Migrations/` 下的迁移
5. **执行填充** → 执行 `Database/Seeders/` 下的填充器（失败不阻塞）
6. **调用钩子** → 调用插件的 `install()` 方法
7. **创建锁文件** → 生成 `install.lock` 标记安装完成

### 卸载流程

1. **依赖检查** → 确保没有其他插件依赖此插件
2. **调用钩子** → 调用插件的 `uninstall()` 方法
3. **回滚迁移** → 根据 `rollback_on_uninstall` 配置或 `--rollback` 选项决定
4. **删除锁文件** → 移除 `install.lock` 文件

### 启动流程

1. **应用启动** → `PluginBootListener` 监听 `BootApplication` 事件
2. **扫描插件** → 发现所有已安装且 `enabled: true` 的插件
3. **优先级排序** → 按 `priority` 降序排列
4. **加载插件** → 依次加载 ConfigProvider、实例化 Plugin 类、调用 `boot()` 方法
5. **错误隔离** → 单个插件加载失败会记录日志，不影响其他插件

## 命令行工具

| 命令 | 说明 |
|------|------|
| `plugin:make [name]` | 生成插件骨架 |
| `plugin:install <name>` | 安装插件 |
| `plugin:uninstall <name>` | 卸载插件 |
| `plugin:list` | 查看插件列表 |
| `plugin:seed <name>` | 执行插件填充器 |

### 常用选项

```bash
# 生成插件（包含迁移、填充器、命令）
php bin/hyperf.php plugin:make my-plugin -m -s -c

# 安装时跳过迁移
php bin/hyperf.php plugin:install vendor/my-plugin --skip-migrations

# 安装时跳过填充器
php bin/hyperf.php plugin:install vendor/my-plugin --skip-seeders

# 卸载时强制回滚迁移
php bin/hyperf.php plugin:uninstall vendor/my-plugin --rollback

# 强制卸载（忽略依赖检查）
php bin/hyperf.php plugin:uninstall vendor/my-plugin --force

# 查看已启用的插件
php bin/hyperf.php plugin:list --status=enabled

# JSON 格式输出
php bin/hyperf.php plugin:list --json
```

## ⚠️ 注意事项

### 1. 启用/禁用状态由用户维护

插件的 `enabled` 状态完全由用户在 `plugin.json` 中手动设置。设置为 `true` 后，插件会在应用启动时自动加载。

### 2. 依赖插件必须启用

安装插件时，如果 `dependencies` 中声明了依赖插件，这些依赖插件必须：
- 已安装（有 `install.lock` 文件）
- 已启用（`plugin.json` 中 `enabled: true`）

如果依赖插件未启用，安装会失败并提示用户先启用依赖插件。

### 3. publish 目录文件需自行管理

`publish/` 目录下的文件（如配置文件、视图等）需要在插件的 `install()` 和 `uninstall()` 钩子中自行管理：

```php
class Plugin extends AbstractPlugin
{
    public function install(): void
    {
        // 发布配置文件到主项目
        $source = __DIR__ . '/../publish/my_plugin.php';
        $dest = BASE_PATH . '/config/autoload/my_plugin.php';
        
        if (!file_exists($dest)) {
            copy($source, $dest);
        }
    }

    public function uninstall(): void
    {
        // 清理发布的配置文件
        $configFile = BASE_PATH . '/config/autoload/my_plugin.php';
        
        if (file_exists($configFile)) {
            unlink($configFile);
        }
    }
}
```

### 4. Composer 依赖安装说明

`composer_require` 中声明的包会被安装到主项目的 `vendor` 目录，并记录到主项目的 `composer.json` 中。这样 `composer update` 时不会丢失这些依赖。

卸载插件时，这些依赖不会自动移除。如需移除，请手动执行：

```bash
composer remove package/name
```

### 5. 迁移由 Hyperf 管理

插件的迁移文件通过 Hyperf 的 `migrate` 命令执行，迁移记录存储在 Hyperf 的 `migrations` 表中，而不是 `install.lock` 文件。

## ConfigProvider

插件可以通过 ConfigProvider 注册命令、监听器、依赖注入等：

```php
<?php

namespace Vendor\MyPlugin;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                Command\MyCommand::class,
            ],
            'listeners' => [
                Listener\MyListener::class,
            ],
            'dependencies' => [
                Contract\MyInterface::class => Service\MyService::class,
            ],
        ];
    }
}
```

## 事件

| 事件 | 触发时机 |
|------|----------|
| `PluginInstalledEvent` | 安装成功后 |
| `PluginUninstalledEvent` | 卸载成功后 |
| `PluginBootedEvent` | 启动后 |
| `PluginMigratedEvent` | 迁移执行后 |
| `PluginSeededEvent` | 填充执行后 |

## 许可证

MIT License
