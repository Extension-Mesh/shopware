<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Service\RepositoryOnboardingService;
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
final class RepositoryController
{
    public function __construct(private readonly RepositoryOnboardingService $onboarding)
    {
    }

    #[Route(
        path: '/api/_action/extension-mesh/repositories',
        name: 'api.action.extension_mesh.repositories.list',
        methods: [Request::METHOD_GET]
    )]
    public function list(Request $request): JsonResponse
    {
        $result = $this->onboarding->paginate(
            \max(1, $request->query->getInt('page', 1)),
            \max(1, \min(100, $request->query->getInt('limit', 10)))
        );

        return new JsonResponse([
            ...$result,
            'providers' => $this->onboarding->providers(),
        ]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/repositories',
        name: 'api.action.extension_mesh.repositories.connect',
        methods: [Request::METHOD_POST]
    )]
    public function connect(Request $request): JsonResponse
    {
        try {
            $data = $this->json($request);

            return new JsonResponse([
                'data' => $this->onboarding->connect(
                    $this->string($data, 'provider', 'github'),
                    $this->string($data, 'repository'),
                    $this->string($data, 'apiBaseUrl', 'https://api.github.com'),
                    $this->string($data, 'accessToken'),
                    $this->string($data, 'mode'),
                    \is_string($data['productId'] ?? null) ? $data['productId'] : null
                ),
            ], Response::HTTP_ACCEPTED);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/repositories/{id}/sync',
        name: 'api.action.extension_mesh.repositories.sync',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_POST]
    )]
    public function synchronize(string $id): JsonResponse
    {
        try {
            return new JsonResponse(
                ['data' => $this->onboarding->queueSynchronization($id)],
                Response::HTTP_ACCEPTED
            );
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/repositories/{id}/credential',
        name: 'api.action.extension_mesh.repositories.credential',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_PUT]
    )]
    public function credential(string $id, Request $request): JsonResponse
    {
        try {
            $data = $this->json($request);
            return new JsonResponse([
                'data' => $this->onboarding->updateCredential(
                    $id,
                    $this->string($data, 'accessToken')
                ),
            ]);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/repositories/{id}',
        name: 'api.action.extension_mesh.repositories.unlink',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_DELETE]
    )]
    public function unlink(string $id): Response
    {
        try {
            $this->onboarding->unlink($id);

            return new Response(status: Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $data = \json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw ExtensionMeshException::invalidRepository('the request body must be a JSON object.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (!\is_string($value)) {
            throw ExtensionMeshException::invalidRepository(\sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    private function error(string $detail): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => '400',
                'code' => 'EXTENSION_MESH__INVALID_REPOSITORY',
                'title' => 'Repository onboarding failed',
                'detail' => $detail,
            ]],
        ], Response::HTTP_BAD_REQUEST);
    }
}
