<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use PHPUnit\Framework\TestCase;

final class AdministrationAdapterContractTest extends TestCase
{
    public function testTheAdapterAddsOnlyNestedRoutesToTheExistingExtensionExperience(): void
    {
        $root = __DIR__ . '/../../src/Resources/app/administration/src';
        $main = \file_get_contents($root . '/main.js');
        $module = \file_get_contents($root . '/module/extension-mesh/index.js');
        $indexTemplate = \file_get_contents(
            $root . '/extension/sw-extension-my-extensions-index/sw-extension-my-extensions-index.html.twig'
        );
        self::assertIsString($main);
        self::assertIsString($module);
        self::assertIsString($indexTemplate);

        self::assertStringContainsString('sw-extension-my-extensions-listing', $main);
        self::assertStringContainsString('sw-extension-my-extensions-index', $main);
        self::assertStringContainsString('sw-self-maintained-extension-card', $main);
        self::assertStringContainsString('routeMiddleware', $module);
        self::assertStringContainsString('sw.extension.my-extensions.registries', $module);
        self::assertStringContainsString('sw.extension.my-extensions.repositories', $module);
        self::assertStringNotContainsString('navigation:', $module);
        $listing = \file_get_contents(
            $root . '/extension/sw-extension-my-extensions-listing/index.js'
        );
        self::assertIsString($listing);
        self::assertStringContainsString('extensionMesh.owned', $listing);
        self::assertStringContainsString('extension-mesh.tabs.registries', $indexTemplate);
        self::assertStringContainsString('extension-mesh.tabs.repositories', $indexTemplate);
        self::assertStringNotContainsString('sw-modal', $indexTemplate);
        $repositories = \file_get_contents(
            $root . '/page/extension-mesh-repositories/extension-mesh-repositories.html.twig'
        );
        self::assertIsString($repositories);
        self::assertStringContainsString('tokenCreate', $repositories);
        self::assertStringContainsString('v-model="repositoryToken"', $repositories);
        self::assertStringNotContainsString('!repositoryToken.trim()', $repositories);
        self::assertStringContainsString('v-if="showApiBaseUrl"', $repositories);
        self::assertSame(2, \substr_count($repositories, '<sw-pagination'));
        self::assertSame(2, \substr_count($repositories, '<sw-data-grid'));
        self::assertStringContainsString('<mt-switch', $repositories);
        self::assertStringContainsString('extension-mesh-field-group', $repositories);
        self::assertStringContainsString('<sw-modal', $repositories);
        self::assertStringContainsString('repositoryTotal', $repositories);
        self::assertStringContainsString('publicationTotal', $repositories);
        self::assertStringNotContainsString('zeroTrustNotice', $repositories);
        self::assertStringNotContainsString('extension-mesh-token-guide', $repositories);
        self::assertStringNotContainsString('githubConnect', $repositories);
        self::assertStringNotContainsString('showManual', $repositories);
        $repositoryComponent = \file_get_contents(
            $root . '/page/extension-mesh-repositories/index.js'
        );
        self::assertIsString($repositoryComponent);
        self::assertStringContainsString(
            'https://github.com/settings/personal-access-tokens/new?',
            $repositoryComponent
        );
        self::assertStringContainsString("contents: 'read'", $repositoryComponent);
        self::assertStringContainsString("parameters.set('target_name', owner)", $repositoryComponent);
        self::assertStringContainsString('onRepositoryPageChange', $repositoryComponent);
        self::assertStringContainsString('onPublicationPageChange', $repositoryComponent);
        self::assertStringContainsString('scheduleRepositoryPoll', $repositoryComponent);
        self::assertStringContainsString('pollRepositoryProgress', $repositoryComponent);
        self::assertStringContainsString('repository.onboardingStatus', $repositoryComponent);
        self::assertStringContainsString('repositoryStatusLabel(item)', $repositories);
        self::assertStringNotContainsString(
            'startGithubDevice',
            $repositoryComponent
        );
        $downloadForm = \file_get_contents(
            $root . '/extension/sw-product-download-form/sw-product-download-form.html.twig'
        );
        $downloadComponent = \file_get_contents(
            $root . '/extension/sw-product-download-form/index.js'
        );
        $productDetail = \file_get_contents(
            $root . '/extension/sw-product-detail/index.js'
        );
        self::assertIsString($downloadForm);
        self::assertIsString($downloadComponent);
        self::assertIsString($productDetail);
        self::assertStringContainsString('{% parent %}', $downloadForm);
        self::assertStringContainsString('<mt-switch', $downloadForm);
        self::assertStringContainsString('<sw-data-grid', $downloadForm);
        self::assertStringContainsString('<sw-pagination', $downloadForm);
        self::assertStringContainsString('extensionMeshEnabled', $downloadComponent);
        self::assertStringContainsString("this.\$super('successfulUpload'", $downloadComponent);
        self::assertStringContainsString("this.\$super('onRemoveDownload'", $downloadComponent);
        self::assertStringContainsString("setLimit(1)", $productDetail);
        self::assertDirectoryDoesNotExist(
            $root . '/extension/sw-extension-my-extensions-listing-controls'
        );
    }
}
