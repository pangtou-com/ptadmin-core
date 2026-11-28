<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

\PHPUnit\TextUI\Configuration\Registry::loadFrom($argv[5]);

/** 独立进程连接同一隔离测试库，验证真实行锁下的并发草稿创建。 */
final class SchemaDraftWorker extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\PTAdmin\Core\Providers\PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'mysql', 'unix_socket' => $GLOBALS['argv'][1], 'database' => $GLOBALS['argv'][2],
            'username' => 'root', 'password' => '', 'prefix' => 'pt_',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]);
    }

    public function runWorker(): void
    {
        $this->setUp();
        try {
            $barrier = $GLOBALS['argv'][3];
            file_put_contents($GLOBALS['argv'][4], 'ready');
            $deadline = microtime(true) + 15;
            while (!is_file($barrier)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('并发测试等待超时');
                }
                usleep(10000);
            }
            $draft = app(\PTAdmin\Schema\Contracts\ResourceServiceInterface::class)->beginDraftEdit('test.recovery')->toArray();
            echo json_encode($draft['version'], JSON_THROW_ON_ERROR);
        } finally {
            $this->tearDown();
        }
    }
}

(new SchemaDraftWorker('runWorker'))->runWorker();
