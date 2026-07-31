<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Storefront;

use ExtensionMesh\Shopware\Infrastructure\Persistence\PublicationRepository;
use ExtensionMesh\Shopware\Service\CustomerProductAccessResolver;
use ExtensionMesh\Shopware\Service\ReleaseCompatibilityGrouper;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class AccountLicenseController extends StorefrontController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly CustomerProductAccessResolver $access,
        private readonly PublicationRepository $releases,
        private readonly ReleaseCompatibilityGrouper $releaseGrouper,
        private readonly MediaService $mediaService,
        private readonly GenericPageLoaderInterface $pageLoader
    ) {
    }

    #[Route(
        path: '/account/extension-licenses',
        name: 'frontend.extension_mesh.licenses',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function licenses(
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): Response {
        $licenses = $this->access->paginateProducts(
            $customer->getId(),
            $context->getSalesChannelId(),
            $request->query->getInt('page', 1),
            self::PAGE_SIZE,
            $context->getContext()
        );

        return $this->privateResponse($this->renderStorefront(
            '@ExtensionMesh/storefront/page/account/extension-mesh-licenses.html.twig',
            [
                'page' => $this->pageLoader->load($request, $context),
                'extensionMeshLicenses' => $licenses,
            ]
        ));
    }

    #[Route(
        path: '/account/extension-licenses/{productId}',
        name: 'frontend.extension_mesh.licenses.detail',
        requirements: ['productId' => '[0-9a-f]{32}'],
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function detail(
        string $productId,
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): Response {
        $license = $this->access->product(
            $customer->getId(),
            $productId,
            $context->getSalesChannelId(),
            $context->getContext()
        );
        if ($license === null) {
            throw $this->createNotFoundException();
        }
        $compatibilityOptions = $this->releaseGrouper->sortConstraints(
            $this->releases->compatibilityOptionsForProduct($productId, $context->getContext())
        );
        $selectedCompatibility = \trim($request->query->getString('shopware'));
        if (!\in_array($selectedCompatibility, $compatibilityOptions, true)) {
            $selectedCompatibility = '';
        }
        $releases = $selectedCompatibility === ''
            ? ['items' => [], 'page' => 1, 'hasPrevious' => false, 'hasNext' => false]
            : $this->releases->paginateValidForProduct(
                $productId,
                $selectedCompatibility,
                $request->query->getInt('page', 1),
                self::PAGE_SIZE,
                $context->getContext()
            );
        $releases['groups'] = $this->releaseGrouper->group($releases['items']);

        return $this->privateResponse($this->renderStorefront(
            '@ExtensionMesh/storefront/page/account/extension-mesh-license-detail.html.twig',
            [
                'page' => $this->pageLoader->load($request, $context),
                'extensionMeshLicense' => $license,
                'extensionMeshReleases' => $releases,
                'extensionMeshCompatibilityOptions' => $compatibilityOptions,
                'extensionMeshSelectedCompatibility' => $selectedCompatibility,
            ]
        ));
    }

    #[Route(
        path: '/account/extension-licenses/{productId}/releases/{releaseId}/download',
        name: 'frontend.extension_mesh.licenses.download',
        requirements: [
            'productId' => '[0-9a-f]{32}',
            'releaseId' => '[0-9a-f]{32}',
        ],
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function download(
        string $productId,
        string $releaseId,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): Response {
        $release = $this->releases->get($releaseId, $context->getContext());
        if (
            $release === null
            || $release['productId'] !== $productId
            || $release['validationError'] !== null
            || !\is_array($release['metadata'])
            || !\is_string($release['sha256'])
            || !$this->access->grants(
                $customer->getId(),
                $productId,
                $context->getSalesChannelId(),
                $context->getContext()
            )
        ) {
            throw $this->createNotFoundException();
        }

        $stream = $context->getContext()->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $systemContext) => $this->mediaService->loadFileStream(
                $release['mediaId'],
                $systemContext
            )
        );
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(1024 * 1024);
            }
        });
        $technicalName = \is_string($release['technicalName']) ? $release['technicalName'] : 'extension';
        $version = \is_string($release['version']) ? $release['version'] : 'release';
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $technicalName . '-' . $version . '.zip')
        );
        $response->headers->set('Content-Type', 'application/zip');

        return $this->privateResponse($response);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
