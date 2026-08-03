<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Tests\Unit\Api;

use ExtensionMesh\Shopware\Api\EntitlementController;
use ExtensionMesh\Shopware\Api\PublisherController;
use ExtensionMesh\Shopware\Api\RegistryController;
use ExtensionMesh\Shopware\Api\RepositoryController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Symfony\Component\Routing\Attribute\Route;

final class ApiAclTest extends TestCase
{
    /**
     * @param class-string $controller
     * @param list<string> $expectedAcl
     */
    #[DataProvider('routeAclProvider')]
    public function testActionUsesTheLeastPrivilegeRequired(
        string $controller,
        string $method,
        array $expectedAcl
    ): void {
        $attributes = (new \ReflectionMethod($controller, $method))->getAttributes(Route::class);

        self::assertCount(1, $attributes);
        $route = $attributes[0]->newInstance();
        self::assertSame($expectedAcl, $route->defaults[PlatformRequest::ATTRIBUTE_ACL] ?? null);
    }

    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function routeAclProvider(): iterable
    {
        yield 'registry add' => [RegistryController::class, 'add', ['extension_mesh_registry_source:create']];
        yield 'registry credential' => [RegistryController::class, 'credential', ['extension_mesh_registry_source:update']];
        yield 'registry delete' => [RegistryController::class, 'delete', ['extension_mesh_registry_source:delete']];
        yield 'registry refresh' => [RegistryController::class, 'refresh', ['extension_mesh_registry_source:update']];
        yield 'registry list' => [RegistryController::class, 'extensions', ['extension_mesh_registry_source:read']];
        yield 'registry install' => [RegistryController::class, 'download', [
            'extension_mesh_registry_source:read',
            'system.plugin_maintain',
        ]];
        yield 'repository providers' => [RepositoryController::class, 'providers', ['extension_mesh_repository_connection:read']];
        yield 'repository connect' => [RepositoryController::class, 'connect', ['extension_mesh_repository_connection:create']];
        yield 'repository sync' => [RepositoryController::class, 'synchronize', ['extension_mesh_repository_connection:update']];
        yield 'repository credential' => [RepositoryController::class, 'credential', ['extension_mesh_repository_connection:update']];
        yield 'repository unlink' => [RepositoryController::class, 'unlink', ['extension_mesh_repository_connection:delete']];
    }

    /**
     * @param class-string $controller
     */
    #[DataProvider('controllerAclProvider')]
    public function testSinglePurposeControllerUsesItsEntityPrivilege(
        string $controller,
        string $expectedAcl
    ): void {
        $attributes = (new \ReflectionClass($controller))->getAttributes(Route::class);

        self::assertCount(1, $attributes);
        $route = $attributes[0]->newInstance();
        self::assertSame([$expectedAcl], $route->defaults[PlatformRequest::ATTRIBUTE_ACL] ?? null);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function controllerAclProvider(): iterable
    {
        yield 'entitlements' => [EntitlementController::class, 'extension_mesh_entitlement:read'];
        yield 'publication sync' => [PublisherController::class, 'extension_mesh_published_release:update'];
    }
}
