<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerLicenseAccountContractTest extends TestCase
{
    public function testLicenseAndReleaseScreensUseBoundedIndependentPages(): void
    {
        $root = __DIR__ . '/../../src';
        $access = \file_get_contents($root . '/Service/CustomerProductAccessResolver.php');
        $publications = \file_get_contents(
            $root . '/Infrastructure/Persistence/PublicationRepository.php'
        );
        $controller = \file_get_contents($root . '/Storefront/AccountLicenseController.php');
        $migration = \file_get_contents(
            $root . '/Migration/Migration1785261000AccountLicensePagination.php'
        );
        $filterMigration = \file_get_contents(
            $root . '/Migration/Migration1785262000ReleaseCompatibilityFilter.php'
        );

        self::assertIsString($access);
        self::assertIsString($publications);
        self::assertIsString($controller);
        self::assertIsString($migration);
        self::assertIsString($filterMigration);
        self::assertStringContainsString('setLimit($limit + 1)', $access);
        self::assertStringContainsString('TOTAL_COUNT_MODE_NONE', $access);
        self::assertStringContainsString('setLimit($limit + 1)', $publications);
        self::assertStringContainsString('TOTAL_COUNT_MODE_NONE', $publications);
        self::assertStringContainsString('private const PAGE_SIZE = 20', $controller);
        self::assertSame(3, \substr_count(
            $controller,
            'PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true'
        ));
        self::assertStringContainsString(
            '(`product_id`, `created_at`, `id`)',
            $migration
        );
        self::assertStringContainsString(
            '(`product_id`, `shopware_constraint`, `created_at`, `id`)',
            $filterMigration
        );
    }

    public function testDownloadAuthorizationUsesSessionCustomerAndProductAccessPolicy(): void
    {
        $controller = \file_get_contents(
            __DIR__ . '/../../src/Storefront/AccountLicenseController.php'
        );

        self::assertIsString($controller);
        self::assertStringContainsString('CustomerEntity $customer', $controller);
        self::assertStringContainsString('$this->access->grants(', $controller);
        self::assertStringContainsString("\$release['productId'] !== \$productId", $controller);
        self::assertStringContainsString("'Cache-Control', 'private, no-store, max-age=0'", $controller);
        self::assertStringNotContainsString('accessToken', $controller);
        self::assertStringNotContainsString('Authorization', $controller);
    }

    public function testAccessCanGainFuturePolicyProvidersWithoutPerCustomerRows(): void
    {
        $root = __DIR__ . '/../../src';
        $provider = \file_get_contents($root . '/Service/CustomerProductAccessProvider.php');
        $resolver = \file_get_contents($root . '/Service/CustomerProductAccessResolver.php');
        $services = \file_get_contents($root . '/Resources/config/services.yaml');

        self::assertIsString($provider);
        self::assertIsString($resolver);
        self::assertIsString($services);
        self::assertStringContainsString('interface CustomerProductAccessProvider', $provider);
        self::assertStringContainsString('Providers are combined with OR', $provider);
        self::assertStringContainsString('foreach ($this->providers as $provider)', $resolver);
        self::assertStringContainsString('extension_mesh.customer_product_access_provider', $services);
    }

    public function testOrdersLinkToLicenseScreenInsteadOfDuplicatingReleaseHistory(): void
    {
        $template = \file_get_contents(
            __DIR__ . '/../../src/Resources/views/storefront/component/line-item/element/downloads.html.twig'
        );

        self::assertIsString($template);
        self::assertStringContainsString('frontend.extension_mesh.licenses.detail', $template);
        self::assertStringNotContainsString('showOlder', $template);
        self::assertStringNotContainsString('olderReleases', $template);
        self::assertStringNotContainsString('frontend.account.order.single.download', $template);
    }

    public function testLicenseDetailKeepsTheSanitizedReleaseChangelog(): void
    {
        $template = \file_get_contents(
            __DIR__ . '/../../src/Resources/views/storefront/page/account/'
                . 'extension-mesh-license-detail.html.twig'
        );

        self::assertIsString($template);
        self::assertStringContainsString('release.metadata.releaseNotes', $template);
        self::assertStringContainsString('extension_mesh_release_notes', $template);
        self::assertStringContainsString('extensionMesh.licenses.changelog', $template);
        self::assertStringContainsString('extensionMesh.downloads.compatibleWith', $template);
        self::assertStringContainsString('extensionMesh.downloads.latestVersion', $template);
        self::assertStringContainsString('name="shopware"', $template);
        self::assertStringContainsString('extensionMesh.licenses.selectCompatibility', $template);
    }
}
