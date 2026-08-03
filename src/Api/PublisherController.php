<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Message\PublicationSyncMessage;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_published_release:update'],
])]
final class PublisherController
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    #[Route(
        path: '/api/_action/extension-mesh/publication/synchronize',
        name: 'api.action.extension_mesh.publication.synchronize',
        methods: [Request::METHOD_POST]
    )]
    public function synchronize(): JsonResponse
    {
        $this->messageBus->dispatch(new PublicationSyncMessage());

        return new JsonResponse(['queued' => true], Response::HTTP_ACCEPTED);
    }
}
