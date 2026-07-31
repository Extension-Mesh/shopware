<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['system.plugin_maintain'],
])]
final class EntitlementController
{
    public function __construct(private readonly EntitlementRepository $entitlements)
    {
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements/options',
        name: 'api.action.extension_mesh.entitlements.options',
        methods: [Request::METHOD_GET]
    )]
    public function options(Context $context): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'eligibleProductIds' => $this->entitlements->eligibleProductIds($context),
            ],
        ]);
    }
}
