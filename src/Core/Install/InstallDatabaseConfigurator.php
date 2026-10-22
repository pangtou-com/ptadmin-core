<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Database\DatabaseManager;
use PDO;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use Throwable;

/**
 * 将安装表单中的数据库设置转换为 Laravel 连接配置，并在迁移前验证连接。
 * SQLite 固定使用宿主 storage/database 目录，服务端数据库使用安装表单提供的连接信息。
 */
final class InstallDatabaseConfigurator
{
    private const DEFAULT_SQLITE_DATABASE = 'ptadmin.db';

    /** @var list<string> PTAdmin 首次迁移会创建的表，用于阻止覆盖既有安装。 */
    private const PTADMIN_TABLES = [
        'schema_resources',
        'schema_resource_versions',
        'schema_field_projections',
        'schema_publish_tasks',
        'schema_audit_events',
        'ptadmin_sessions',
        'admins',
        'admin_roles',
        'admin_resources',
        'admin_user_roles',
        'admin_grants',
        'admin_dashboard_widgets',
        'assets',
        'notice_channels',
        'notice_scenes',
        'notice_templates',
        'notice_messages',
        'notice_deliveries',
        'ptadmin_config_definitions',
        'ptadmin_config_values',
        'admin_login_logs',
        'admin_operation_records',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly InstallDatabaseDrivers $drivers,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{driver:string,database:string,host:string,port:string,username:string,password:string,prefix:string}
     */
    public function configure(array $input): array
    {
        $driver = strtolower(trim((string) ($input['db_connection'] ?? $input['database_driver'] ?? config('ptadmin.install.database_default', 'mysql'))));
        $this->drivers->assertAvailable($driver);

        $current = (array) config('database.connections.'.$driver, []);
        $database = trim((string) ($input['db_database'] ?? ($driver === 'sqlite' ? self::DEFAULT_SQLITE_DATABASE : 'ptadmin')));
        $prefix = (string) ($input['db_prefix'] ?? 'pt_');
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw new InvalidInstallStateException('数据表前缀只能包含字母、数字和下划线。');
        }

        if ($driver === 'sqlite') {
            $database = $database === '' ? self::DEFAULT_SQLITE_DATABASE : $database;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $database) !== 1
                || $database === '.'
                || $database === '..') {
                throw new InvalidInstallStateException('SQLite 数据库只能填写文件名，不能包含目录路径。');
            }
            $databasePath = storage_path('database/'.$database);
            if (!is_file($databasePath)) {
                $directory = dirname($databasePath);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new InvalidInstallStateException('无法创建 SQLite 数据库目录。');
                }
                if (false === touch($databasePath)) {
                    throw new InvalidInstallStateException('无法创建 SQLite 数据库文件。');
                }
            }
            $settings = [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => $prefix,
                'foreign_key_constraints' => true,
            ];
            $environmentDatabase = $this->environmentDatabaseValue($databasePath);
        } else {
            $host = trim((string) ($input['db_host'] ?? ($current['host'] ?? '127.0.0.1')));
            $port = trim((string) ($input['db_port'] ?? (($current['port'] ?? '') ?: ($driver === 'pgsql' ? '5432' : '3306'))));
            $username = (string) ($input['db_username'] ?? ($current['username'] ?? ''));
            $password = (string) ($input['db_password'] ?? ($current['password'] ?? ''));
            if (preg_match('/[\x00-\x1F\x7F]/', $database) === 1) {
                throw new InvalidInstallStateException('数据库名称包含非法字符。');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $host.$username.$password) === 1 || str_contains($host, ';')) {
                throw new InvalidInstallStateException('数据库连接信息包含非法字符。');
            }
            if ($database === '' || $host === '' || $username === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new InvalidInstallStateException('数据库主机、端口、数据库名称和账户不能为空。');
            }
            $maximumLength = $driver === 'pgsql' ? 63 : 64;
            if (preg_match('/^[A-Za-z0-9_-]{1,'.$maximumLength.'}$/', $database) !== 1) {
                throw new InvalidInstallStateException('数据库名称只能包含字母、数字、下划线和短横线，长度不能超过 '.$maximumLength.' 个字符。');
            }
            if ($driver === 'mysql') {
                $this->ensureMysqlDatabase($host, $port, $database, $username, $password);
            }
            $settings = [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'prefix' => $prefix,
            ];
            $settings += $driver === 'pgsql' ? [
                'charset' => 'utf8',
                'search_path' => $current['search_path'] ?? 'public',
                'sslmode' => $current['sslmode'] ?? 'prefer',
            ] : [
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'engine' => null,
            ];
            $environmentDatabase = $database;
        }

        $config = (array) config('database');
        $config['default'] = $driver;
        $config['connections'] = array_merge((array) ($config['connections'] ?? []), [$driver => $settings]);
        config(['database' => $config]);
        $this->database->purge();

        try {
            $this->database->connection($driver)->getPdo();
            $this->assertNoConflictingTables($driver, $prefix);
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidInstallStateException) {
                throw $exception;
            }
            throw new InvalidInstallStateException('数据库连接失败：'.$exception->getMessage(), previous: $exception);
        }

        return [
            'driver' => $driver,
            'database' => $environmentDatabase,
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (string) ($settings['port'] ?? ''),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'prefix' => $prefix,
        ];
    }

    /** @return array<string, mixed> */
    public function environmentValues(array $settings): array
    {
        $values = [
            'DB_CONNECTION' => $settings['driver'],
            'DB_DATABASE' => $settings['database'],
            'DB_HOST' => $settings['host'],
            'DB_PORT' => $settings['port'],
            'DB_USERNAME' => $settings['username'],
            'DB_PASSWORD' => $settings['password'],
            'DB_SOCKET' => '',
            'DATABASE_URL' => '',
            'DB_PREFIX' => $settings['prefix'],
        ];
        return $values;
    }

    private function environmentDatabaseValue(string $database): string
    {
        if (!str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($database, $base)) {
            return substr($database, strlen($base));
        }

        return $database;
    }

    private function ensureMysqlDatabase(string $host, string $port, string $database, string $username, string $password): void
    {
        // 托管环境中的安装账户通常只能访问已经创建好的数据库，没有 CREATE DATABASE 权限；
        // 先尝试直接连接目标库，成功时不再要求额外的服务器级权限。
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            return;
        } catch (Throwable) {
            // 目标数据库可能尚未创建，继续使用服务器连接创建它。
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $quotedDatabase = '`'.str_replace('`', '``', $database).'`';
            $pdo->exec('CREATE DATABASE IF NOT EXISTS '.$quotedDatabase.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            throw new InvalidInstallStateException('MySQL 数据库连接或创建失败：'.$exception->getMessage(), previous: $exception);
        }
    }

    private function assertNoConflictingTables(string $driver, string $prefix): void
    {
        if ($driver === 'sqlite' && (string) config('database.connections.sqlite.database') === ':memory:') {
            return;
        }

        $connection = $this->database->connection($driver);
        $query = match ($driver) {
            'sqlite' => "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'",
            'pgsql' => 'select tablename from pg_catalog.pg_tables where schemaname = current_schema()',
            default => 'show tables',
        };
        $tables = array_map(
            static fn (object $row): string => (string) array_values(get_object_vars($row))[0],
            $connection->select($query),
        );

        foreach ($tables as $table) {
            $unprefixed = $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
            if (in_array($unprefixed, self::PTADMIN_TABLES, true)) {
                throw new InvalidInstallStateException(sprintf('数据库中已存在 PTAdmin 数据表：%s。请使用空数据库或更换表前缀。', $table));
            }
        }
    }
}
