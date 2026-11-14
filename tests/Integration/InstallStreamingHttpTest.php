<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class InstallStreamingHttpTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function streamingCases(): iterable
    {
        yield 'nested buffers' => [false, false, false];
        yield 'nested buffers and compression' => [true, false, false];
        yield 'migration failure after progress' => [true, true, false];
        yield 'client disconnects during migration' => [true, false, true];
        yield 'migration fails after client disconnects' => [false, true, true];
    }

    #[DataProvider('streamingCases')]
    public function testProgressArrivesBeforeMigrationCanFinish(bool $compression, bool $failMigration, bool $disconnect): void
    {
        $root = sys_get_temp_dir().'/ptadmin-stream-http-'.bin2hex(random_bytes(6));
        mkdir($root.'/storage', 0700, true);
        mkdir($root.'/public', 0700, true);
        if ($failMigration) {
            file_put_contents($root.'/fail-migration', '1');
        }
        $server = null;
        $connection = null;

        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            self::assertIsResource($socket, $error);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            self::assertIsString($address);
            $server = new Process([
                PHP_BINARY, '-d', 'output_buffering=4096', '-d', 'zlib.output_compression='.($compression ? '1' : '0'),
                '-d', 'ignore_user_abort=0', '-d', 'max_execution_time=30',
                '-S', $address, dirname(__DIR__).'/Fixtures/Install/stream-router.php',
            ], dirname(__DIR__, 2), ['PTADMIN_STREAM_TEST_ROOT' => $root]);
            $server->start();
            $this->waitForServer($address, $server);

            $connection = stream_socket_client('tcp://'.$address, $errno, $error, 3);
            self::assertIsResource($connection, $error);
            stream_set_timeout($connection, 3);
            $body = http_build_query([
                'username' => 'stream-founder', 'nickname' => 'Stream Founder',
                'password' => 'stream-password', 'password_confirmation' => 'stream-password',
                'db_connection' => 'sqlite', 'db_database' => 'stream.sqlite', 'db_prefix' => '',
                'ptadmin_web_prefix' => 'stream_admin',
            ]);
            fwrite($connection, "POST /__ptadmin__/install/stream HTTP/1.1\r\nHost: {$address}\r\n"
                ."Content-Type: application/x-www-form-urlencoded\r\nAccept-Encoding: gzip\r\n"
                .'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n".$body);

            $headers = '';
            while (($line = fgets($connection)) !== false && $line !== "\r\n") {
                $headers .= $line;
            }
            self::assertStringContainsString('200 OK', $headers, $server->getErrorOutput()
                .(is_file($root.'/storage/laravel.log') ? file_get_contents($root.'/storage/laravel.log') : ''));
            self::assertStringContainsString('application/x-ndjson', $headers);
            self::assertStringContainsString('X-Accel-Buffering: no', $headers);
            self::assertStringContainsString('no-transform', $headers);
            self::assertStringNotContainsString('content-encoding: gzip', strtolower($headers));

            $events = [];
            while (($line = fgets($connection)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $events[] = $event;
                if ($event['message'] === '正在执行数据库迁移') {
                    self::assertFileDoesNotExist($root.'/storage/app/ptadmin/install/installed.json');
                    self::assertFileDoesNotExist($root.'/continue-migration');
                    if ($disconnect) {
                        stream_socket_shutdown($connection, STREAM_SHUT_RDWR);
                        fclose($connection);
                        $connection = null;
                        file_put_contents($root.'/continue-migration', '1');
                        break;
                    }
                    file_put_contents($root.'/continue-migration', '1');
                }
            }
            if (!$disconnect) {
                self::assertFalse(stream_get_meta_data($connection)['timed_out'], 'Progress was buffered until the installation finished.');
            }
            $deadline = microtime(true) + 10;
            while (!is_file($root.'/runtime-after.json') && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($root.'/runtime-after.json', $server->getErrorOutput());
            $during = json_decode(file_get_contents($root.'/runtime-during.json'), true, 512, JSON_THROW_ON_ERROR);
            $after = json_decode(file_get_contents($root.'/runtime-after.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $during['ignore_user_abort']);
            self::assertSame(0, $during['max_execution_time']);
            self::assertSame(0, $after['ignore_user_abort']);
            self::assertSame(30, $after['max_execution_time']);
            if ($disconnect && !$failMigration) {
                self::assertSame(1, $after['connection_aborted']);
            }
            self::assertFileExists($root.'/migration-waiting');
            self::assertFileExists($root.'/continue-migration', 'The migration log must arrive while installation is still running.');
            if (!$disconnect) {
                self::assertSame($failMigration ? 'error' : 'success', $events[array_key_last($events)]['type'] ?? null, json_encode($events));
            }
            $state = json_decode(file_get_contents($root.'/storage/app/ptadmin/install/state.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($failMigration ? 'failed' : 'installed', $state['stage']);
            self::assertStringNotContainsString('stream-password', json_encode($events));
            if ($failMigration) {
                self::assertFileDoesNotExist($root.'/storage/app/ptadmin/install/installed.json');
                self::assertStringNotContainsString('Isolated migration failure', json_encode($events));
            } else {
                self::assertFileExists($root.'/storage/app/ptadmin/install/installed.json');
                if (!$disconnect) {
                    self::assertStringEndsWith('/stream_admin/', $events[array_key_last($events)]['data']['admin_url']);
                }
                $database = new \PDO('sqlite:'.$root.'/storage/database/stream.sqlite');
                self::assertSame('stream-founder', $database->query('select username from admins')->fetchColumn());
                self::assertFileExists($root.'/public/stream_admin/index.html');
                self::assertStringContainsString('PTADMIN_WEB_PREFIX=stream_admin', file_get_contents($root.'/.env'));
            }
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
            $server?->stop(1);
            (new Filesystem())->deleteDirectory($root);
        }
    }

    private function waitForServer(string $address, Process $server): void
    {
        $deadline = microtime(true) + 5;
        while ($server->isRunning() && microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fwrite($socket, "GET /health HTTP/1.1\r\nHost: {$address}\r\nConnection: close\r\n\r\n");
                stream_set_timeout($socket, 1);
                $response = stream_get_contents($socket);
                fclose($socket);
                if (str_contains($response, 'ready')) {
                    return;
                }
            }
            usleep(10000);
        }
        self::fail('The isolated PHP HTTP server did not start: '.$server->getErrorOutput());
    }
}
