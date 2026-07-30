<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionMeshProductRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ProductDownloadCatalogRepository;
use ExtensionMesh\Shopware\Message\PublicationSyncMessage;
use ExtensionMesh\Shopware\Service\RepositoryProductWriter;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['product.editor'],
])]
final class ProductDownloadController
{
    public function __construct(
        private readonly ExtensionMeshProductRepository $products,
        private readonly ProductDownloadCatalogRepository $downloads,
        private readonly RepositoryProductWriter $productWriter,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    #[Route(
        path: '/api/_action/extension-mesh/products/{productId}/integration',
        name: 'api.action.extension_mesh.product.integration.get',
        requirements: ['productId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_GET]
    )]
    public function integration(string $productId): JsonResponse
    {
        if (!$this->productWriter->productExists($productId)) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $this->products->status($productId)]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/products/{productId}/integration',
        name: 'api.action.extension_mesh.product.integration.update',
        requirements: ['productId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_PUT]
    )]
    public function updateIntegration(string $productId, Request $request): JsonResponse
    {
        if (!$this->productWriter->productExists($productId)) {
            return $this->notFound();
        }

        try {
            $data = \json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->invalid('The request body must be valid JSON.');
        }
        if (!\is_array($data) || !\is_bool($data['enabled'] ?? null)) {
            return $this->invalid('"enabled" must be a boolean.');
        }

        $status = $this->products->status($productId);
        if ($status['source'] === 'repository' && $data['enabled'] === false) {
            return $this->invalid('Repository-managed products cannot be disconnected here.');
        }

        $this->products->setManual($productId, $data['enabled']);
        $this->messageBus->dispatch(new PublicationSyncMessage());

        return new JsonResponse(['data' => $this->products->status($productId)]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/products/{productId}/downloads',
        name: 'api.action.extension_mesh.product.downloads',
        requirements: ['productId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_GET]
    )]
    public function downloads(string $productId, Request $request): JsonResponse
    {
        if (!$this->productWriter->productExists($productId)) {
            return $this->notFound();
        }

        return new JsonResponse($this->downloads->paginate(
            $productId,
            \max(1, $request->query->getInt('page', 1)),
            \max(1, \min(100, $request->query->getInt('limit', 10)))
        ));
    }

    #[Route(
        path: '/api/_action/extension-mesh/products/{productId}/publication',
        name: 'api.action.extension_mesh.product.publication',
        requirements: ['productId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_POST]
    )]
    public function refreshPublication(string $productId): JsonResponse
    {
        if (!$this->productWriter->productExists($productId)) {
            return $this->notFound();
        }
        if (!$this->products->status($productId)['enabled']) {
            return $this->invalid('The product is not connected to Extension Mesh.');
        }

        $this->messageBus->dispatch(new PublicationSyncMessage());

        return new JsonResponse(['queued' => true], Response::HTTP_ACCEPTED);
    }

    private function invalid(string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [[
            'status' => '400',
            'code' => 'EXTENSION_MESH__INVALID_PRODUCT_INTEGRATION',
            'title' => 'Product integration could not be updated',
            'detail' => $detail,
        ]]], Response::HTTP_BAD_REQUEST);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['errors' => [[
            'status' => '404',
            'code' => 'EXTENSION_MESH__PRODUCT_NOT_FOUND',
            'title' => 'Product not found',
            'detail' => 'The Shopware product does not exist.',
        ]]], Response::HTTP_NOT_FOUND);
    }
}
