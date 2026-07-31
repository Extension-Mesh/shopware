import '../../component/extension-mesh-entitlement-form';
import '../../page/extension-mesh-entitlement-list';
import '../../page/extension-mesh-entitlement-create';
import '../../page/extension-mesh-entitlement-detail';

const { Module } = Shopware;

Module.register('extension-mesh-entitlement', {
    type: 'plugin',
    name: 'ExtensionMeshEntitlement',
    title: 'extension-mesh.entitlements.title',
    description: 'extension-mesh.entitlements.explanation',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#189eff',
    icon: 'regular-key',

    routes: {
        index: {
            component: 'extension-mesh-entitlement-list',
            path: 'index',
            meta: {
                privilege: 'extension_mesh.viewer',
            },
        },
        create: {
            component: 'extension-mesh-entitlement-create',
            path: 'create',
            meta: {
                parentPath: 'extension.mesh.entitlement.index',
                privilege: 'extension_mesh.creator',
            },
        },
        detail: {
            component: 'extension-mesh-entitlement-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'extension.mesh.entitlement.index',
                privilege: 'extension_mesh.editor',
            },
            props: {
                default: (route) => ({
                    entitlementId: route.params.id.toLowerCase(),
                }),
            },
        },
    },

    navigation: [
        {
            id: 'extension-mesh-entitlement',
            path: 'extension.mesh.entitlement.index',
            label: 'extension-mesh.entitlements.menuLabel',
            parent: 'sw-order',
            position: 20,
            privilege: 'extension_mesh.viewer',
        },
    ],
});
