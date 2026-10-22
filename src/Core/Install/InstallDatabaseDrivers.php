<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use PDO;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;

/** 安装界面与执行阶段共用当前 PHP 进程的 PDO 驱动能力。 */
final class InstallDatabaseDrivers
{
    private const DRIVERS = [
        'sqlite' => ['extension' => 'pdo_sqlite', 'port' => ''],
        'mysql' => ['extension' => 'pdo_mysql', 'port' => '3306'],
        'pgsql' => ['extension' => 'pdo_pgsql', 'port' => '5432'],
    ];

    /** @var list<string> */
    private readonly array $availableDrivers;

    /** @param list<string>|null $availableDrivers */
    public function __construct(?array $availableDrivers = null)
    {
        $this->availableDrivers = $availableDrivers ?? (class_exists(PDO::class) ? PDO::getAvailableDrivers() : []);
    }

    /** @return list<array{driver:string,label:string,extension:string,port:string,available:bool}> */
    public function options(): array
    {
        $options = [];
        foreach (self::DRIVERS as $driver => $definition) {
            if (!in_array($driver, config('ptadmin.install.database_drivers', array_keys(self::DRIVERS)), true)) {
                continue;
            }
            $options[] = [
                'driver' => $driver,
                'label' => __('ptadmin::install.database_options.'.$driver),
                'extension' => $definition['extension'],
                'port' => $definition['port'],
                'available' => in_array($driver, $this->availableDrivers, true),
            ];
        }

        return $options;
    }

    public function defaultDriver(): ?string
    {
        $available = array_column(array_filter($this->options(), static fn (array $option): bool => $option['available']), 'driver');
        $preferred = config('ptadmin.install.database_default', 'mysql');

        return in_array($preferred, $available, true) ? $preferred : ($available[0] ?? null);
    }

    public function assertAvailable(string $driver): void
    {
        foreach ($this->options() as $option) {
            if ($option['driver'] !== $driver) {
                continue;
            }
            if (!$option['available']) {
                throw new InvalidInstallStateException(__('ptadmin::install.database_driver_unavailable', [
                    'database' => $option['label'],
                    'extension' => $option['extension'],
                ]));
            }

            return;
        }

        throw new InvalidInstallStateException(__('ptadmin::install.database_driver_unsupported'));
    }
}
