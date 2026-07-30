<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Storefront;

use ExtensionMesh\Shopware\Service\AccessTokenService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class AccountAccessController extends StorefrontController
{
    public function __construct(
        private readonly AccessTokenService $tokens,
        private readonly GenericPageLoaderInterface $pageLoader
    ) {
    }

    #[Route(
        path: '/account/extension-mesh',
        name: 'frontend.extension_mesh.account',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function account(
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): Response {
        $page = $this->pageLoader->load($request, $context);
        $token = $this->tokens->getOrCreate($customer->getId(), $context->getSalesChannelId());
        $response = $this->renderStorefront(
            '@ExtensionMesh/storefront/page/account/extension-mesh.html.twig',
            [
                'page' => $page,
                'extensionMeshToken' => $token,
                'extensionMeshRegistryUrl' => $request->getSchemeAndHttpHost() . '/extension-mesh/v1/registry',
            ]
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    #[Route(
        path: '/account/extension-mesh/rotate',
        name: 'frontend.extension_mesh.account.rotate',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_POST]
    )]
    public function rotate(SalesChannelContext $context, CustomerEntity $customer): Response
    {
        $this->tokens->rotate($customer->getId(), $context->getSalesChannelId());

        return $this->redirectToRoute('frontend.extension_mesh.account');
    }
}
