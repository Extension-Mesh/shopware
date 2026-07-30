<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Storefront;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Persistence\EntitlementRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use ExtensionMesh\Shopware\Service\AccessTokenService;
use ExtensionMesh\Shopware\Service\PublicationSynchronizer;
use ExtensionMesh\Shopware\Service\PublisherCatalogService;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class PublisherController extends AbstractController
{
    public function __construct(
        private readonly AccessTokenService $tokens,
        private readonly PublisherCatalogService $catalog,
        private readonly PublicationSynchronizer $synchronizer,
        private readonly PublicationRepository $releases,
        private readonly EntitlementRepository $entitlements,
        private readonly MediaService $mediaService
    ) {
    }

    #[Route(
        path: '/extension-mesh/v1/registry',
        name: 'frontend.extension_mesh.registry',
        methods: [Request::METHOD_GET]
    )]
    public function registry(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        try {
            $token = $this->authenticate($request, $salesChannelContext);
            $artifactTemplate = $request->getSchemeAndHttpHost() . '/extension-mesh/v1/artifacts/{releaseId}';
            $document = $this->catalog->forCustomer(
                $token['customerId'],
                $token['salesChannelId'],
                $artifactTemplate,
                $salesChannelContext->getContext()
            );

            return $this->privateJson($document);
        } catch (ExtensionMeshException $exception) {
            return $this->privateJson([
                'errors' => [[
                    'status' => '401',
                    'code' => 'EXTENSION_MESH__ACCESS_DENIED',
                    'title' => 'Registry access denied',
                    'detail' => $exception->getMessage(),
                ]],
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route(
        path: '/extension-mesh/v1/artifacts/{releaseId}',
        name: 'frontend.extension_mesh.artifact',
        requirements: ['releaseId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_GET]
    )]
    public function artifact(
        string $releaseId,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): Response {
        try {
            $token = $this->authenticate($request, $salesChannelContext);
            $this->synchronizer->synchronize($salesChannelContext->getContext());
            $release = $this->releases->get($releaseId);
            if (
                $release === null
                || $release['validationError'] !== null
                || !$this->entitlements->isEntitled(
                    $token['customerId'],
                    $release['productId'],
                    $token['salesChannelId']
                )
            ) {
                throw ExtensionMeshException::accessDenied('this release is not covered by an active entitlement.');
            }

            $stream = $salesChannelContext->getContext()->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $context) => $this->mediaService->loadFileStream($release['mediaId'], $context)
            );
            $response = new StreamedResponse(static function () use ($stream): void {
                while (!$stream->eof()) {
                    echo $stream->read(1024 * 1024);
                }
            });
            $response->headers->set(
                'Content-Disposition',
                HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $release['technicalName'] . '-' . $release['version'] . '.zip'
                )
            );
            $response->headers->set('Content-Type', 'application/zip');
            $this->privateHeaders($response);

            return $response;
        } catch (ExtensionMeshException $exception) {
            return $this->privateJson([
                'errors' => [[
                    'status' => '401',
                    'code' => 'EXTENSION_MESH__ACCESS_DENIED',
                    'title' => 'Artifact access denied',
                    'detail' => $exception->getMessage(),
                ]],
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * @return array{id: string, customerId: string, salesChannelId: string}
     */
    private function authenticate(Request $request, SalesChannelContext $context): array
    {
        $token = $this->tokens->authenticate($request->headers->get('Authorization'));
        if ($token['salesChannelId'] !== $context->getSalesChannelId()) {
            throw ExtensionMeshException::accessDenied('the token belongs to another sales channel.');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $this->privateHeaders($response);

        return $response;
    }

    private function privateHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Authorization');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }
}
