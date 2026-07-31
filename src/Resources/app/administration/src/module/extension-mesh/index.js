import '../../page/extension-mesh-administration';
import '../../page/extension-mesh-registries';
import '../../page/extension-mesh-repositories';

const { Module } = Shopware;

Module.register('extension-mesh-administration', {
    type: 'plugin',
    name: 'ExtensionMesh',
    title: 'extension-mesh.tabs.registries',
    description: 'extension-mesh.registries.explanation',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#189eff',
    icon: 'regular-plug',

    routes: {
        index: {
            component: 'extension-mesh-administration',
            path: 'index',
            redirect: {
                name: 'extension.mesh.administration.index.registries',
            },
            meta: {
                privilege: 'extension_mesh.viewer',
            },
            children: {
                registries: {
                    component: 'extension-mesh-registries',
                    path: 'registries',
                    meta: {
                        privilege: 'extension_mesh.viewer',
                    },
                },
                repositories: {
                    component: 'extension-mesh-repositories',
                    path: 'repositories',
                    meta: {
                        privilege: 'extension_mesh.viewer',
                    },
                },
            },
        },
    },

    navigation: [
        {
            id: 'extension-mesh-administration',
            path: 'extension.mesh.administration.index.registries',
            label: 'Extension Mesh',
            parent: 'sw-extension',
            position: 20,
            privilege: 'extension_mesh.viewer',
        },
    ],
});
