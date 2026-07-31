<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Storefront;

use ExtensionMesh\Shopware\Service\AccessTokenService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
final class StoreApiAccessController
{
    public function __construct(private readonly AccessTokenService $tokens)
    {
    }

    #[Route(
        path: '/store-api/extension-mesh/access',
        name: 'store-api.extension_mesh.access',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => false,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function access(
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): JsonResponse {
        return $this->response(
            $this->tokens->getOrCreate(
                $customer->getId(),
                $context->getSalesChannelId(),
                $context->getContext()
            ),
            $request
        );
    }

    #[Route(
        path: '/store-api/extension-mesh/access/rotate',
        name: 'store-api.extension_mesh.access.rotate',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => false,
        ],
        methods: [Request::METHOD_POST]
    )]
    public function rotate(
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): JsonResponse {
        return $this->response(
            $this->tokens->rotate(
                $customer->getId(),
                $context->getSalesChannelId(),
                $context->getContext()
            ),
            $request
        );
    }

    private function response(?string $accessToken, Request $request): JsonResponse
    {
        $response = new JsonResponse([
            'registryUrl' => $request->getSchemeAndHttpHost() . '/extension-mesh/v1/registry',
            'accessToken' => $accessToken,
            'hasEntitlements' => $accessToken !== null,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
