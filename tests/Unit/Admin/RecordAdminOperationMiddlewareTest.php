<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Http\Middleware\RecordAdminOperationMiddleware;
use Symfony\Component\HttpFoundation\Response;

final class RecordAdminOperationMiddlewareTest extends TestCase
{
    public function testBinaryContentsAreMaskedAtEveryDepthWithoutChangingTheRequest(): void
    {
        $input = [
            'name' => 'release.zip',
            'content_base64' => base64_encode(str_repeat('private package', 200)),
            'context' => ['data' => ['CONTENT-BASE64' => 'private nested package', 'password' => 'secret']],
            'package_size' => 3000,
        ];
        $request = Request::create('/admin/app/market/addon/releases/7/package', 'POST', $input);
        $request->attributes->set('ptadmin.session', ['guard_name' => 'admin', 'subject_id' => 1]);
        $database = $this->createMock(ConnectionInterface::class);
        $query = $this->createMock(Builder::class);
        $database->expects(self::once())->method('table')->with('admin_operation_records')->willReturn($query);
        $query->expects(self::once())->method('insert')->willReturnCallback(function (array $record): bool {
            self::assertSame(422, $record['status_code']);
            self::assertFalse($record['successful']);
            self::assertSame([
                'name' => 'release.zip',
                'content_base64' => '[masked]',
                'context' => ['data' => ['CONTENT-BASE64' => '[masked]', 'password' => '[masked]']],
                'package_size' => 3000,
            ], json_decode($record['request_payload'], true, 512, JSON_THROW_ON_ERROR));

            return true;
        });

        $response = (new RecordAdminOperationMiddleware($database))->handle($request, function (Request $incoming) use ($input): Response {
            self::assertSame($input, $incoming->input());

            return new Response('invalid package', 422);
        });
        self::assertSame('invalid package', $response->getContent());
        self::assertSame($input, $request->input());
    }
}
