<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Auth;

use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Auth\AccountAuthorizationIdentityResolver;
use PTAdmin\Admin\Auth\AuthorizationIdentity;
use PTAdmin\Admin\Auth\AuthorizationIdentityResolverInterface;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\DataScopeBoundaryInterface;
use PTAdmin\Core\Auth\DataScopeRegistry;
use PTAdmin\Core\Auth\SchemaScopeResolver;
use PTAdmin\Schema\Contract\ServerConstraint;

final class AuthorizationIdentityTest extends TestCase
{
    public function testDefaultIdentityReadsOnlyActiveAccountRoles(): void
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE admin_user_roles (admin_id INTEGER, role_id INTEGER)');
        $db->statement('CREATE TABLE admin_roles (id INTEGER, status INTEGER, deleted_at TEXT)');
        $db->statement('INSERT INTO admin_roles VALUES (1, 1, NULL), (2, 0, NULL), (3, 1, "2026-10-01")');
        $db->statement('INSERT INTO admin_user_roles VALUES (7, 1), (7, 2), (7, 3)');
        $admin = new Admin();
        $admin->forceFill(['id' => 7, 'is_founder' => false]);
        $identity = (new AccountAuthorizationIdentityResolver($db))->resolve($admin);
        self::assertSame([1], $identity->roleIds);
        self::assertTrue($identity->directGrants);
        self::assertFalse($identity->superuser);
        self::assertSame([], $identity->attributes);
    }

    public function testHostRestrictionsApplyBeforeSuperuserPrivileges(): void
    {
        $resolver = $this->resolver(false);
        $service = new AuthorizationService(new SQLiteConnection(new PDO('sqlite::memory:')), $resolver);
        $decision = $service->decision(new Admin(), 'update', 'admin.roles');
        self::assertFalse($decision->allowed);
        self::assertSame('identity_restricted', $decision->reason);
    }

    public function testMandatoryBoundaryAlsoAppliesToSuperusers(): void
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE schema_resources (resource_key TEXT, deleted_at TEXT, active_version_id INTEGER)');
        $authorization = new AuthorizationService($db, $this->resolver(true));
        $boundary = new class implements DataScopeBoundaryInterface {
            public function resolve(Admin $admin, string $resourceKey, string $action, array $schema): ?ServerConstraint
            {
                return new ServerConstraint(['field' => 'organization_id', 'operator' => 'eq', 'value' => 8]);
            }
        };
        $scopes = new SchemaScopeResolver($authorization, $db, new DataScopeRegistry(), $boundary);
        self::assertSame(['field' => 'organization_id', 'operator' => 'eq', 'value' => 8], $scopes->resolve(new Admin(), 'business.records', 'list')->toArray());
        self::assertNull((new SchemaScopeResolver($authorization, $db, new DataScopeRegistry()))->resolve(new Admin(), 'business.records', 'list'));
    }

    private function resolver(bool $permitted): AuthorizationIdentityResolverInterface
    {
        return new class($permitted) implements AuthorizationIdentityResolverInterface {
            public function __construct(private readonly bool $permitted)
            {
            }

            public function resolve(Admin $admin): AuthorizationIdentity
            {
                return new AuthorizationIdentity([], false, true);
            }

            public function permits(Admin $admin, string $resourceKey, string $ability): bool
            {
                return $this->permitted;
            }
        };
    }
}
