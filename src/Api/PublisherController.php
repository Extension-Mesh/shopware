<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Service\PublisherCatalogService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['system.plugin_maintain'],
])]
final class PublisherController extends AbstractController
{
    public function __construct(private readonly PublisherCatalogService $catalog)
    {
    }

    #[Route(
        path: '/api/_action/extension-mesh/publication',
        name: 'api.action.extension_mesh.publication',
        methods: [Request::METHOD_GET]
    )]
    public function status(Request $request, Context $context): JsonResponse
    {
        $result = $this->catalog->publicationStatus(
            $context,
            \max(1, $request->query->getInt('page', 1)),
            \max(1, \min(100, $request->query->getInt('limit', 10))),
            $request->query->getBoolean('synchronize', true)
        );

        return new JsonResponse([
            ...$result,
            'registryPath' => '/extension-mesh/v1/registry',
            'accountPath' => '/account/extension-mesh',
        ]);
    }
}
