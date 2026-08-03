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
        name: 'api.action.extension_mesh.registries.add',
        methods: [Request::METHOD_POST],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_registry_source:create']]
    )]
    public function add(Request $request, Context $context): JsonResponse
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

            $id = $this->catalog->addSource($url, $accessToken, $context);

            return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries/{id}/credential',
        name: 'api.action.extension_mesh.registries.credential',
        requirements: ['id' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_PUT],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_registry_source:update']]
    )]
    public function credential(string $id, Request $request, Context $context): Response
    {
        try {
            $data = $request->toArray();
            $accessToken = $data['accessToken'] ?? null;
            if ($accessToken !== null && !\is_string($accessToken)) {
                throw ExtensionMeshException::invalidCredential('it must be a string or null.');
            }
            $this->catalog->updateCredential($id, $accessToken, $context);

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException|\JsonException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/registries/{id}',
        name: 'api.action.extension_mesh.registries.delete',
        methods: [Request::METHOD_DELETE],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_registry_source:delete']]
    )]
    public function delete(string $id, Context $context): Response
    {
        try {
            $this->sources->remove($id, $context);

            return new Response('', Response::HTTP_NO_CONTENT);
        } catch (ExtensionMeshException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route(
        path: '/api/_action/extension-mesh/refresh',
        name: 'api.action.extension_mesh.refresh',
        methods: [Request::METHOD_POST],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_registry_source:update']]
    )]
    public function refresh(Context $context): JsonResponse
    {
        $this->catalog->refreshAll($context);

        return new JsonResponse(['data' => $this->publicSources($context)]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/extensions',
        name: 'api.action.extension_mesh.extensions',
        methods: [Request::METHOD_GET],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['extension_mesh_registry_source:read']]
    )]
    public function extensions(Request $request, Context $context): JsonResponse
    {
        $locale = $request->query->getString('locale', 'en-GB');

        return new JsonResponse([
            'data' => $this->catalog->catalog($this->shopwareVersion, \PHP_VERSION, $locale, $context),
        ]);
    }

    #[Route(
        path: '/api/_action/extension-mesh/download/{registryId}/{technicalName}',
        name: 'api.action.extension_mesh.download',
        requirements: [
            'registryId' => '[0-9a-f]{32}',
            'technicalName' => '[A-Za-z][A-Za-z0-9]*',
        ],
        methods: [Request::METHOD_POST],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => [
            'extension_mesh_registry_source:read',
            'system.plugin_maintain',
        ]]
    )]
    public function download(string $registryId, string $technicalName, Context $context): Response
    {
        try {
            $download = $this->catalog->download(
                $registryId,
                $technicalName,
                $this->shopwareVersion,
                \PHP_VERSION,
                $context
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
    private function publicSources(Context $context): array
    {
        return \array_map(
            static fn (array $source): array => [
                'id' => $source['id'],
                'url' => $source['url'],
                'label' => $source['label'],
                'lastRefreshedAt' => $source['lastRefreshedAt'],
                'lastError' => $source['lastError'],
            ],
            $this->sources->all($context)
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
