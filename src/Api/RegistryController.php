<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Api;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RegistrySourceRepository;
use ExtensionMesh\Shopware\Service\CatalogService;
use ExtensionMesh\Shopware\Service\ExtensionInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    PlatformRequest::ATTRIBUTE_ACL => ['system.plugin_maintain'],
])]
final class RegistryController extends AbstractController
{
    public function __construct(
        private readonly RegistrySourceRepository $sources,
        private readonly CatalogService $catalog,
        private readonly ExtensionInstaller $installer,
        private readonly string $shopwareVersion
    ) {
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries',
        name: 'api.action.extension_mesh.registries.list',
        methods: [Request::METHOD_GET]
    )]
    public function sources(): JsonResponse
    {
        $sources = \array_map(
            static fn (array $source): array => [
                'id' => $source['id'],
                'url' => $source['url'],
                'resolvedUrl' => $source['normalizedUrl'],
                'label' => $source['label'],
                'enabled' => $source['enabled'],
                'hasCredential' => $source['credentialCiphertext'] !== null,
                'credentialFingerprint' => $source['credentialFingerprint'],
                'lastRefreshedAt' => $source['lastRefreshedAt'],
                'lastError' => $source['lastError'],
            ],
            $this->sources->all()
        );

        return new JsonResponse(['data' => $sources]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries',
        name: 'api.action.extension_mesh.registries.add',
        methods: [Request::METHOD_POST]
    )]
    public function add(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
            $url = $data['url'] ?? null;
            if (!\is_string($url)) {
                throw ExtensionMeshException::invalidRegistryUrl('a URL is required.');
            }

            $accessToken = $data['accessToken'] ?? null;
            if ($accessToken !== null && !\is_string($accessToken)) {
                throw ExtensionMeshException::invalidCredential('it must be a string.');
            }

            $id = $this->catalog->addSource($url, $accessToken);

            return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries/{id}/credential',
        name: 'api.action.extension_mesh.registries.credential',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_PUT]
    )]
    public function credential(string $id, Request $request): Response
    {
        try {
            $data = $request->toArray();
            $accessToken = $data['accessToken'] ?? null;
            if ($accessToken !== null && !\is_string($accessToken)) {
                throw ExtensionMeshException::invalidCredential('it must be a string or null.');
            }
            $this->catalog->updateCredential($id, $accessToken);

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries/{id}',
        name: 'api.action.extension_mesh.registries.delete',
        methods: [Request::METHOD_DELETE]
    )]
    public function delete(string $id): Response
    {
        try {
            $this->sources->remove($id);

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/refresh',
        name: 'api.action.extension_mesh.refresh',
        methods: [Request::METHOD_POST]
    )]
    public function refresh(): JsonResponse
    {
        $this->catalog->refreshAll();

        return new JsonResponse(['data' => $this->publicSources()]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/extensions',
        name: 'api.action.extension_mesh.extensions',
        methods: [Request::METHOD_GET]
    )]
    public function extensions(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale', 'en-GB');

        return new JsonResponse([
            'data' => $this->catalog->catalog($this->shopwareVersion, \PHP_VERSION, $locale),
        ]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/download/{registryId}/{technicalName}',
        name: 'api.action.extension_mesh.download',
        requirements: [
            'registryId' => '[0-9a-f]{32}',
            'technicalName' => '[A-Za-z][A-Za-z0-9]*',
        ],
        methods: [Request::METHOD_POST]
    )]
    public function download(string $registryId, string $technicalName, Context $context): Response
    {
        try {
            $download = $this->catalog->download(
                $registryId,
                $technicalName,
                $this->shopwareVersion,
                \PHP_VERSION
            );
            $this->installer->prepare(
                $download['release'],
                $technicalName,
                $download['registryUrl'],
                $context,
                $download['accessToken'],
                $download['credentialOrigin']
            );

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicSources(): array
    {
        return \array_map(
            static fn (array $source): array => [
                'id' => $source['id'],
                'url' => $source['url'],
                'label' => $source['label'],
                'lastRefreshedAt' => $source['lastRefreshedAt'],
                'lastError' => $source['lastError'],
            ],
            $this->sources->all()
        );
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code' => 'EXTENSION_MESH__REQUEST_FAILED',
                'title' => 'ExtensionMesh request failed',
                'detail' => $detail,
            ]],
        ], $status);
    }
}
