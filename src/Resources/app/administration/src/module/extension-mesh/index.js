import '../../page/extension-mesh-registries';
import '../../page/extension-mesh-repositories';

const { Module } = Shopware;

const childRoutes = [
    {
        component: 'extension-mesh-registries',
        name: 'sw.extension.my-extensions.registries',
        path: 'registries',
        meta: {
            privilege: 'system.plugin_maintain',
        },
    },
    {
        component: 'extension-mesh-repositories',
        name: 'sw.extension.my-extensions.repositories',
        path: 'repositories',
        meta: {
            privilege: 'system.plugin_maintain',
        },
    },
];

Module.register('extension-mesh-administration', {
    type: 'plugin',
    name: 'ExtensionMesh',
    title: 'extension-mesh.tabs.registries',
    description: 'extension-mesh.registries.explanation',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#189eff',
    icon: 'regular-plug',
    routes: {},

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.extension.my-extensions') {
            childRoutes.forEach((route) => {
                if (!currentRoute.children.some((child) => child.name === route.name)) {
                    currentRoute.children.push(route);
                }
            });
        }

        next(currentRoute);
    },
});
