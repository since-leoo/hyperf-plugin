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

namespace SinceLeoo\Plugin\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Throwable;

/**
 * 插件生成命令.
 *
 * 交互式生成插件骨架代码，包括目录结构、plugin.json、composer.json、
 * Plugin 主类、ConfigProvider 等文件。
 */
#[Command]
class PluginMakeCommand extends HyperfCommand
{
    protected ?string $name = 'plugin:make';

    protected string $description = 'Generate a new plugin skeleton with interactive prompts';

    /**
     * 插件目录.
     */
    private string $pluginsPath;

    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->pluginsPath = $this->getPluginsPath();

        // 收集插件信息
        $pluginInfo = $this->collectPluginInfo();
        if ($pluginInfo === null) {
            return self::FAILURE;
        }

        // 检查插件是否已存在
        $pluginPath = $this->pluginsPath . '/' . $pluginInfo['directory'];
        if (is_dir($pluginPath) && ! $this->input->getOption('force')) {
            $this->error("Plugin directory already exists: {$pluginPath}");
            $this->line('Use --force to overwrite.');
            return self::FAILURE;
        }

        // 生成插件
        $this->info("Creating plugin '{$pluginInfo['package_name']}'...");
        $this->line('');

        try {
            $this->generatePlugin($pluginInfo, $pluginPath);

            $this->info('Plugin created successfully!');
            $this->line('');
            $this->displayNextSteps($pluginInfo);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to create plugin: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'The plugin name (e.g., my-plugin or vendor/my-plugin)');
        $this->addOption('vendor', null, InputOption::VALUE_OPTIONAL, 'The vendor name');
        $this->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Plugin description');
        $this->addOption('author', 'a', InputOption::VALUE_OPTIONAL, 'Plugin author');
        $this->addOption('with-migration', 'm', InputOption::VALUE_NONE, 'Include migration directory');
        $this->addOption('with-seeder', 's', InputOption::VALUE_NONE, 'Include seeder directory');
        $this->addOption('with-command', 'c', InputOption::VALUE_NONE, 'Include example command');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing plugin');
    }

    /**
     * 收集插件信息.
     */
    private function collectPluginInfo(): ?array
    {
        $helper = $this->getHelper('question');

        // 获取插件名称
        $name = $this->input->getArgument('name');
        if (empty($name)) {
            $question = new Question('<info>Plugin name</info> (e.g., my-plugin): ');
            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Plugin name cannot be empty.');
                }
                return $answer;
            });
            $name = $helper->ask($this->input, $this->output, $question);
        }

        // 解析 vendor 和 plugin name
        if (str_contains($name, '/')) {
            [$vendor, $pluginName] = explode('/', $name, 2);
        } else {
            $vendor = $this->input->getOption('vendor');
            if (empty($vendor)) {
                $question = new Question('<info>Vendor name</info> [vendor]: ', 'vendor');
                $vendor = $helper->ask($this->input, $this->output, $question);
            }
            $pluginName = $name;
        }

        // 标准化名称
        $vendor = $this->normalizeVendorName($vendor);
        $pluginName = $this->normalizePluginName($pluginName);
        $packageName = "{$vendor}/{$pluginName}";

        // 获取描述
        $description = $this->input->getOption('description');
        if (empty($description)) {
            $question = new Question('<info>Description</info> [A Hyperf plugin]: ', 'A Hyperf plugin');
            $description = $helper->ask($this->input, $this->output, $question);
        }

        // 获取作者
        $author = $this->input->getOption('author');
        if (empty($author)) {
            $defaultAuthor = $this->getDefaultAuthor();
            $question = new Question("<info>Author</info> [{$defaultAuthor}]: ", $defaultAuthor);
            $author = $helper->ask($this->input, $this->output, $question);
        }

        // 是否包含迁移目录
        $withMigration = $this->input->getOption('with-migration');
        if (! $withMigration && ! $this->input->getOption('no-interaction')) {
            $question = new ConfirmationQuestion('<info>Include migration directory?</info> [y/N]: ', false);
            $withMigration = $helper->ask($this->input, $this->output, $question);
        }

        // 是否包含填充器目录
        $withSeeder = $this->input->getOption('with-seeder');
        if (! $withSeeder && ! $this->input->getOption('no-interaction')) {
            $question = new ConfirmationQuestion('<info>Include seeder directory?</info> [y/N]: ', false);
            $withSeeder = $helper->ask($this->input, $this->output, $question);
        }

        // 是否包含示例命令
        $withCommand = $this->input->getOption('with-command');
        if (! $withCommand && ! $this->input->getOption('no-interaction')) {
            $question = new ConfirmationQuestion('<info>Include example command?</info> [y/N]: ', false);
            $withCommand = $helper->ask($this->input, $this->output, $question);
        }

        // 确认信息
        $this->line('');
        $this->line('<comment>Plugin Configuration:</comment>');
        $this->line("  Package Name:  {$packageName}");
        $this->line("  Description:   {$description}");
        $this->line("  Author:        {$author}");
        $this->line('  Migration:     ' . ($withMigration ? 'Yes' : 'No'));
        $this->line('  Seeder:        ' . ($withSeeder ? 'Yes' : 'No'));
        $this->line('  Command:       ' . ($withCommand ? 'Yes' : 'No'));
        $this->line('');

        if (! $this->input->getOption('no-interaction')) {
            $question = new ConfirmationQuestion('<info>Proceed with creation?</info> [Y/n]: ', true);
            if (! $helper->ask($this->input, $this->output, $question)) {
                $this->warn('Plugin creation cancelled.');
                return null;
            }
        }

        return [
            'vendor' => $vendor,
            'plugin_name' => $pluginName,
            'package_name' => $packageName,
            'directory' => $pluginName,
            'namespace' => $this->generateNamespace($vendor, $pluginName),
            'description' => $description,
            'author' => $author,
            'with_migration' => $withMigration,
            'with_seeder' => $withSeeder,
            'with_command' => $withCommand,
        ];
    }

    /**
     * 生成插件.
     */
    private function generatePlugin(array $info, string $pluginPath): void
    {
        // 创建目录结构
        $this->createDirectories($info, $pluginPath);

        // 生成文件
        $this->generatePluginJson($info, $pluginPath);
        $this->generatePluginClass($info, $pluginPath);
        $this->generateConfigProvider($info, $pluginPath);

        if ($info['with_migration']) {
            $this->generateExampleMigration($info, $pluginPath);
        }

        if ($info['with_seeder']) {
            $this->generateExampleSeeder($info, $pluginPath);
        }

        if ($info['with_command']) {
            $this->generateExampleCommand($info, $pluginPath);
        }

        $this->generatePublishConfig($info, $pluginPath);
    }

    /**
     * 创建目录结构.
     */
    private function createDirectories(array $info, string $pluginPath): void
    {
        $directories = [
            $pluginPath,
            $pluginPath . '/src',
            $pluginPath . '/publish',
        ];

        if ($info['with_migration']) {
            $directories[] = $pluginPath . '/Database/Migrations';
        }

        if ($info['with_seeder']) {
            $directories[] = $pluginPath . '/Database/Seeders';
        }

        if ($info['with_command']) {
            $directories[] = $pluginPath . '/src/Command';
        }

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
                $this->line("  <info>Created:</info> {$dir}");
            }
        }
    }

    /**
     * 生成 plugin.json.
     */
    private function generatePluginJson(array $info, string $pluginPath): void
    {
        $content = [
            'name' => $info['package_name'],
            'version' => '1.0.0',
            'description' => $info['description'],
            'author' => $info['author'],
            'namespace' => $info['namespace'],
            'priority' => 0,
            'dependencies' => [],
            'composer_require' => new stdClass(),
            'rollback_on_uninstall' => false,
            'enabled' => false,
            'configProvider' => $info['namespace'] . '\ConfigProvider',
        ];

        $filePath = $pluginPath . '/plugin.json';
        file_put_contents($filePath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成 Plugin 主类.
     */
    private function generatePluginClass(array $info, string $pluginPath): void
    {
        $className = $this->getPluginClassName($info['plugin_name']);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$info['namespace']};

use SinceLeoo\\Plugin\\Contract\\AbstractPlugin;

/**
 * {$info['description']}
 */
class Plugin extends AbstractPlugin
{
    /**
     * 插件安装时调用（在迁移和填充之后）
     */
    public function install(): void
    {
        // 自定义安装逻辑
    }

    /**
     * 插件卸载时调用（在回滚迁移之前）
     */
    public function uninstall(): void
    {
        // 自定义卸载逻辑
    }

    /**
     * 插件启用时调用
     */
    public function enable(): void
    {
        // 自定义启用逻辑
    }

    /**
     * 插件禁用时调用
     */
    public function disable(): void
    {
        // 自定义禁用逻辑
    }

    /**
     * 插件启动时调用
     */
    public function boot(): void
    {
        // 自定义启动逻辑
    }
}

PHP;

        $filePath = $pluginPath . '/src/Plugin.php';
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成 ConfigProvider.
     */
    private function generateConfigProvider(array $info, string $pluginPath): void
    {
        $commandsSection = '';
        if ($info['with_command']) {
            $commandsSection = <<<PHP

            // 注册命令
            'commands' => [
                Command\\{$this->getCommandClassName($info['plugin_name'])}::class,
            ],
PHP;
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$info['namespace']};

/**
 * {$info['description']} - ConfigProvider
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [{$commandsSection}
            // 注册监听器
            'listeners' => [],

            // 依赖注入绑定
            'dependencies' => [],

            // 注解扫描路径
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],

            // 发布配置文件
            'publish' => [
                [
                    'id' => 'config',
                    'description' => '{$info['description']} 配置文件',
                    'source' => __DIR__ . '/../publish/{$info['plugin_name']}.php',
                    'destination' => BASE_PATH . '/config/autoload/{$info['plugin_name']}.php',
                ],
            ],
        ];
    }
}

PHP;

        $filePath = $pluginPath . '/src/ConfigProvider.php';
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成示例迁移文件.
     */
    private function generateExampleMigration(array $info, string $pluginPath): void
    {
        $tableName = $this->getTableName($info['plugin_name']);
        $className = 'Create' . $this->studly($tableName) . 'Table';
        $date = date('Y_m_d_His');

        $content = <<<PHP
<?php

declare(strict_types=1);

use Hyperf\\Database\\Schema\\Schema;
use Hyperf\\Database\\Schema\\Blueprint;
use Hyperf\\Database\\Migrations\\Migration;

class {$className} extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->bigIncrements('id');
            
            \$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
}

PHP;

        $filePath = $pluginPath . "/Database/Migrations/{$date}_create_{$tableName}_table.php";
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成示例填充器.
     */
    private function generateExampleSeeder(array $info, string $pluginPath): void
    {
        $className = $this->studly($info['plugin_name']) . 'Seeder';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$info['namespace']}\\Database\\Seeders;

use Hyperf\\Database\\Seeders\\Seeder;
use Hyperf\\DbConnection\\Db;

class {$className} extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 示例：插入初始数据
        // ApplicationContext::getContainer()->get(Db::class)->table('table_name')->insert([
        //     ['name' => 'Example 1', 'description' => 'Description 1'],
        //     ['name' => 'Example 2', 'description' => 'Description 2'],
        // ]);
    }
}

PHP;

        $filePath = $pluginPath . "/Database/Seeders/{$className}.php";
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成示例命令.
     */
    private function generateExampleCommand(array $info, string $pluginPath): void
    {
        $className = $this->getCommandClassName($info['plugin_name']);
        $commandName = str_replace('_', ':', str_replace('-', ':', $info['plugin_name']));

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$info['namespace']}\\Command;

use Hyperf\\Command\\Command as HyperfCommand;
use Hyperf\\Command\\Annotation\\Command;
use Psr\\Container\\ContainerInterface;

/**
 * {$info['description']} - 示例命令
 */
#[Command]
class {$className} extends HyperfCommand
{
    protected ?string \$name = '{$commandName}:hello';

    protected string \$description = '{$info['description']} example command';

    public function __construct(
        private ContainerInterface \$container
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        \$this->info('Hello from {$info['package_name']}!');
        \$this->line('This is an example command. Customize it as needed.');
        
        return self::SUCCESS;
    }
}

PHP;

        $filePath = $pluginPath . "/src/Command/{$className}.php";
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 生成发布配置文件.
     */
    private function generatePublishConfig(array $info, string $pluginPath): void
    {
        $content = <<<PHP
<?php

declare(strict_types=1);

/**
 * {$info['description']} 配置文件
 */

return [
    // 是否启用
    'enabled' => true,

    // 自定义配置项
    // 'option_name' => 'option_value',
];

PHP;

        $filePath = $pluginPath . "/publish/{$info['plugin_name']}.php";
        file_put_contents($filePath, $content);
        $this->line("  <info>Created:</info> {$filePath}");
    }

    /**
     * 显示下一步操作.
     */
    private function displayNextSteps(array $info): void
    {
        $this->line('<comment>Next Steps:</comment>');
        $this->line('');
        $this->line('  1. Navigate to your plugin directory:');
        $this->line("     <info>cd {$this->pluginsPath}/{$info['directory']}</info>");
        $this->line('');
        $this->line('  2. Install the plugin:');
        $this->line("     <info>php bin/hyperf.php plugin:install {$info['package_name']}</info>");
        $this->line('');
        $this->line('  3. Enable the plugin:');
        $this->line("     <info>php bin/hyperf.php plugin:enable {$info['package_name']}</info>");
        $this->line('');
        $this->line('  4. Publish plugin configuration (optional):');
        $this->line("     <info>php bin/hyperf.php vendor:publish {$info['package_name']}</info>");
        $this->line('');
        $this->line('<comment>Plugin Structure:</comment>');
        $this->line("  {$this->pluginsPath}/{$info['directory']}/");
        $this->line('  ├── src/');
        $this->line('  │   ├── Plugin.php');
        $this->line('  │   └── ConfigProvider.php');
        if ($info['with_command']) {
            $this->line('  │   └── Command/');
        }
        if ($info['with_migration']) {
            $this->line('  ├── Database/Migrations/');
        }
        if ($info['with_seeder']) {
            $this->line('  ├── Database/Seeders/');
        }
        $this->line('  ├── publish/');
        $this->line('  └── plugin.json');
        $this->line('');
        $this->line('<comment>plugin.json Fields:</comment>');
        $this->line('  - dependencies: Local plugin dependencies (e.g., ["vendor/base-plugin"])');
        $this->line('  - composer_require: Composer packages (e.g., {"guzzlehttp/guzzle": "^7.0"})');
    }

    /**
     * 获取插件目录路径.
     */
    private function getPluginsPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/plugins';
    }

    /**
     * 标准化 vendor 名称.
     */
    private function normalizeVendorName(string $vendor): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $vendor));
    }

    /**
     * 标准化插件名称.
     */
    private function normalizePluginName(string $name): string
    {
        // 转换为 kebab-case
        $name = preg_replace('/[^a-zA-Z0-9-_]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        return strtolower(trim($name, '-'));
    }

    /**
     * 生成命名空间.
     */
    private function generateNamespace(string $vendor, string $pluginName): string
    {
        $vendor = $this->studly($vendor);
        $plugin = $this->studly($pluginName);
        return "{$vendor}\\{$plugin}";
    }

    /**
     * 获取 Plugin 类名.
     */
    private function getPluginClassName(string $pluginName): string
    {
        return $this->studly($pluginName) . 'Plugin';
    }

    /**
     * 获取命令类名.
     */
    private function getCommandClassName(string $pluginName): string
    {
        return $this->studly($pluginName) . 'Command';
    }

    /**
     * 获取表名.
     */
    private function getTableName(string $pluginName): string
    {
        return str_replace('-', '_', $pluginName);
    }

    /**
     * 转换为 StudlyCase.
     */
    private function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        return str_replace(' ', '', ucwords($value));
    }

    /**
     * 获取默认作者.
     */
    private function getDefaultAuthor(): string
    {
        // 尝试从 git 配置获取
        $name = trim(shell_exec('git config user.name 2>/dev/null') ?? '');
        return $name ?: 'Plugin Author';
    }
}
