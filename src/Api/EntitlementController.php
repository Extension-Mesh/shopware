<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        path: '/api/_action/extension-mesh/entitlements',
        name: 'api.action.extension_mesh.entitlements.list',
        methods: [Request::METHOD_GET]
    )]
    public function list(Request $request): JsonResponse
    {
        $page = \max(1, $request->query->getInt('page', 1));
        $limit = \min(100, \max(1, $request->query->getInt('limit', 25)));
        $result = $this->entitlements->paginate($page, $limit);

        return new JsonResponse([
            'data' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements/options',
        name: 'api.action.extension_mesh.entitlements.options',
        methods: [Request::METHOD_GET]
    )]
    public function options(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'eligibleProductIds' => $this->entitlements->eligibleProductIds(),
            ],
        ]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements/{id}',
        name: 'api.action.extension_mesh.entitlements.detail',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_GET]
    )]
    public function detail(string $id): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $this->entitlements->get($id)]);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements',
        name: 'api.action.extension_mesh.entitlements.create',
        methods: [Request::METHOD_POST]
    )]
    public function create(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request, true);
            $entitlement = $this->entitlements->create(
                $payload['customerId'],
                $payload['productId'],
                $payload['salesChannelId'],
                $payload['orderId'],
                $payload['enabled'],
                $payload['validUntil']
            );

            return new JsonResponse(['data' => $entitlement], Response::HTTP_CREATED);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements/{id}',
        name: 'api.action.extension_mesh.entitlements.update',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_PUT]
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request, false);
            $entitlement = $this->entitlements->update(
                $id,
                $payload['customerId'],
                $payload['productId'],
                $payload['salesChannelId'],
                $payload['orderId'],
                $payload['enabled'],
                $payload['validUntil']
            );

            return new JsonResponse(['data' => $entitlement]);
        } catch (ExtensionMeshException|\JsonException $exception) {
            $status = \str_contains($exception->getMessage(), 'was not found')
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_BAD_REQUEST;

            return $this->error($exception->getMessage(), $status);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/entitlements/{id}',
        name: 'api.action.extension_mesh.entitlements.delete',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_DELETE]
    )]
    public function delete(string $id): Response
    {
        try {
            $this->entitlements->delete($id);

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @return array{
     *     customerId: string,
     *     productId: string,
     *     salesChannelId: string,
     *     orderId: ?string,
     *     enabled: bool,
     *     validUntil: ?\DateTimeImmutable
     * }
     */
    private function payload(Request $request, bool $defaultEnabled): array
    {
        $data = $request->toArray();
        foreach (['customerId', 'productId', 'salesChannelId'] as $field) {
            if (!\is_string($data[$field] ?? null) || $data[$field] === '') {
                throw ExtensionMeshException::invalidEntitlement(
                    \sprintf('%s is required.', $field)
                );
            }
        }

        $orderId = $data['orderId'] ?? null;
        if ($orderId === '') {
            $orderId = null;
        }
        if ($orderId !== null && !\is_string($orderId)) {
            throw ExtensionMeshException::invalidEntitlement(
                'orderId must be a string or null.'
            );
        }

        $enabled = $data['enabled'] ?? $defaultEnabled;
        if (!\is_bool($enabled)) {
            throw ExtensionMeshException::invalidEntitlement('enabled must be a boolean.');
        }

        $validUntil = null;
        if (($data['validUntil'] ?? null) !== null && $data['validUntil'] !== '') {
            if (!\is_string($data['validUntil'])) {
                throw ExtensionMeshException::invalidEntitlement(
                    'validUntil must be a string or null.'
                );
            }
            try {
                $validUntil = new \DateTimeImmutable($data['validUntil']);
            } catch (\Exception) {
                throw ExtensionMeshException::invalidEntitlement(
                    'validUntil must be a valid date and time.'
                );
            }
        }

        return [
            'customerId' => $data['customerId'],
            'productId' => $data['productId'],
            'salesChannelId' => $data['salesChannelId'],
            'orderId' => $orderId,
            'enabled' => $enabled,
            'validUntil' => $validUntil,
        ];
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code' => 'EXTENSION_MESH__ENTITLEMENT_FAILED',
                'title' => 'Entitlement request failed',
                'detail' => $detail,
            ]],
        ], $status);
    }
}
