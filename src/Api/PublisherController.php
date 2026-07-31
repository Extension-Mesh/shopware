<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Service\PublicationSynchronizer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['system.plugin_maintain'],
])]
final class PublisherController
{
    public function __construct(private readonly PublicationSynchronizer $synchronizer)
    {
    }

    #[Route(
        path: '/api/_action/extension-mesh/publication/synchronize',
        name: 'api.action.extension_mesh.publication.synchronize',
        methods: [Request::METHOD_POST]
    )]
    public function synchronize(Context $context): Response
    {
        $this->synchronizer->synchronize($context);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
