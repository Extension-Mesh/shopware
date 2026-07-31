<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit;

use PHPUnit\Framework\TestCase;

final class AdministrationAdapterContractTest extends TestCase
{
    public function testTheAdapterAddsExtensionSourcesAndASeparateEntitlementsModule(): void
    {
        $root = __DIR__ . '/../../src/Resources/app/administration/src';
        $main = \file_get_contents($root . '/main.js');
        $module = \file_get_contents($root . '/module/extension-mesh/index.js');
        $entitlementModule = \file_get_contents(
            $root . '/module/extension-mesh-entitlement/index.js'
        );
        $administrationTemplate = \file_get_contents(
            $root . '/page/extension-mesh-administration/'
            . 'extension-mesh-administration.html.twig'
        );
        self::assertIsString($main);
        self::assertIsString($module);
        self::assertIsString($entitlementModule);
        self::assertIsString($administrationTemplate);

        self::assertStringContainsString('sw-extension-my-extensions-listing', $main);
        self::assertStringNotContainsString('sw-extension-my-extensions-index', $main);
        self::assertStringContainsString('sw-self-maintained-extension-card', $main);
        self::assertStringNotContainsString('routeMiddleware', $module);
        self::assertStringContainsString("component: 'extension-mesh-administration'", $module);
        self::assertStringContainsString("component: 'extension-mesh-registries'", $module);
        self::assertStringContainsString("component: 'extension-mesh-repositories'", $module);
        self::assertStringContainsString("parent: 'sw-extension'", $module);
        self::assertStringContainsString('navigation:', $module);
        self::assertStringContainsString(
            "import './module/extension-mesh-entitlement'",
            $main
        );
        self::assertStringContainsString(
            "Module.register('extension-mesh-entitlement'",
            $entitlementModule
        );
        self::assertStringContainsString("parent: 'sw-order'", $entitlementModule);
        self::assertStringContainsString(
            "path: 'extension.mesh.entitlement.index'",
            $entitlementModule
        );
        self::assertStringContainsString(
            "component: 'extension-mesh-entitlement-create'",
            $entitlementModule
        );
        self::assertStringContainsString(
            "component: 'extension-mesh-entitlement-detail'",
            $entitlementModule
        );
        $listing = \file_get_contents(
            $root . '/extension/sw-extension-my-extensions-listing/index.js'
        );
        self::assertIsString($listing);
        self::assertStringContainsString('extensionMesh.owned', $listing);
        self::assertStringContainsString(
            'extension.mesh.administration.index.registries',
            $administrationTemplate
        );
        self::assertStringContainsString(
            'extension.mesh.administration.index.repositories',
            $administrationTemplate
        );
        self::assertStringContainsString('<router-view', $administrationTemplate);
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
        $entitlementList = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-list/'
            . 'extension-mesh-entitlement-list.html.twig'
        );
        $entitlementListComponent = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-list/index.js'
        );
        $entitlementCreate = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-create/'
            . 'extension-mesh-entitlement-create.html.twig'
        );
        $entitlementCreateComponent = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-create/index.js'
        );
        $entitlementDetail = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-detail/'
            . 'extension-mesh-entitlement-detail.html.twig'
        );
        $entitlementDetailComponent = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-detail/index.js'
        );
        $entitlementForm = \file_get_contents(
            $root . '/component/extension-mesh-entitlement-form/'
            . 'extension-mesh-entitlement-form.html.twig'
        );
        $entitlementFormComponent = \file_get_contents(
            $root . '/component/extension-mesh-entitlement-form/index.js'
        );
        $entitlementListStyles = \file_get_contents(
            $root . '/page/extension-mesh-entitlement-list/'
            . 'extension-mesh-entitlement-list.scss'
        );
        self::assertIsString($entitlementList);
        self::assertIsString($entitlementListComponent);
        self::assertIsString($entitlementCreate);
        self::assertIsString($entitlementCreateComponent);
        self::assertIsString($entitlementDetail);
        self::assertIsString($entitlementDetailComponent);
        self::assertIsString($entitlementForm);
        self::assertIsString($entitlementFormComponent);
        self::assertIsString($entitlementListStyles);
        self::assertStringContainsString('<sw-page', $entitlementList);
        self::assertStringContainsString('sw-data-grid', $entitlementList);
        self::assertStringContainsString(':full-page="true"', $entitlementList);
        self::assertStringContainsString('<sw-search-bar', $entitlementList);
        self::assertStringContainsString('<sw-sidebar-filter-panel', $entitlementList);
        self::assertStringNotContainsString('<sw-sidebar-collapse', $entitlementList);
        self::assertStringContainsString('#column-customerNumber', $entitlementList);
        self::assertStringContainsString('#column-customerEmail', $entitlementList);
        self::assertStringContainsString('@criteria-changed="updateCriteria"', $entitlementList);
        self::assertStringContainsString('deleteEntitlement', $entitlementListComponent);
        self::assertStringContainsString("property: 'customerNumber'", $entitlementListComponent);
        self::assertStringContainsString("property: 'customerEmail'", $entitlementListComponent);
        self::assertStringContainsString('listFilters()', $entitlementListComponent);
        self::assertStringContainsString('activeFilters()', $entitlementListComponent);
        self::assertStringContainsString('<sw-page', $entitlementCreate);
        self::assertStringContainsString('<sw-card-view', $entitlementCreate);
        self::assertStringContainsString('<sw-button-process', $entitlementCreate);
        self::assertStringContainsString('size="default"', $entitlementCreate);
        self::assertStringNotContainsString('<sw-modal', $entitlementCreate);
        self::assertStringContainsString('createEntitlement', $entitlementCreateComponent);
        self::assertStringContainsString('<sw-page', $entitlementDetail);
        self::assertStringContainsString('<sw-card-view', $entitlementDetail);
        self::assertStringContainsString('<sw-button-process', $entitlementDetail);
        self::assertStringContainsString('size="default"', $entitlementDetail);
        self::assertStringNotContainsString('<sw-modal', $entitlementDetail);
        self::assertStringContainsString('getEntitlement', $entitlementDetailComponent);
        self::assertStringContainsString('updateEntitlement', $entitlementDetailComponent);
        self::assertStringContainsString('entity="customer"', $entitlementForm);
        self::assertStringContainsString('entity="product"', $entitlementForm);
        self::assertStringContainsString('entity="sales_channel"', $entitlementForm);
        self::assertStringContainsString('entity="order"', $entitlementForm);
        self::assertStringContainsString('<sw-container', $entitlementForm);
        self::assertStringContainsString('columns="1fr 1fr"', $entitlementForm);
        self::assertStringContainsString('label-property="customerNumber"', $entitlementForm);
        self::assertStringContainsString('<sw-product-variant-info', $entitlementForm);
        self::assertStringContainsString(':criteria="productCriteria"', $entitlementForm);
        self::assertStringContainsString(':criteria="orderCriteria"', $entitlementForm);
        self::assertStringContainsString('<mt-datepicker', $entitlementForm);
        self::assertStringContainsString('Criteria.equalsAny', $entitlementFormComponent);
        self::assertStringContainsString("Criteria.equals('childCount', 0)", $entitlementFormComponent);
        self::assertStringContainsString('selectableProductIds()', $entitlementFormComponent);
        self::assertStringContainsString('this.form.productId', $entitlementFormComponent);
        self::assertStringContainsString(
            "criteria.addAssociation('options.group')",
            $entitlementFormComponent
        );
        self::assertStringNotContainsString(
            "criteria.addAssociation('parent')",
            $entitlementFormComponent
        );
        self::assertStringContainsString('position: absolute', $entitlementListStyles);
        self::assertStringContainsString(':has(.mt-empty-state)', $entitlementListStyles);
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
