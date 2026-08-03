Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'system',
    key: 'extension_mesh',
    roles: {
        viewer: {
            privileges: [
                'extension_mesh_registry_source:read',
                'extension_mesh_repository_connection:read',
                'extension_mesh_published_release:read',
                'extension_mesh_entitlement:read',
                'customer:read',
                'product:read',
                'sales_channel:read',
                'order:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'extension_mesh_registry_source:update',
                'extension_mesh_repository_connection:update',
                'extension_mesh_published_release:update',
                'extension_mesh_entitlement:update',
            ],
            dependencies: [
                'extension_mesh.viewer',
            ],
        },
        creator: {
            privileges: [
                'extension_mesh_registry_source:create',
                'extension_mesh_repository_connection:create',
                'extension_mesh_entitlement:create',
            ],
            dependencies: [
                'extension_mesh.viewer',
                'extension_mesh.editor',
            ],
        },
        deleter: {
            privileges: [
                'extension_mesh_registry_source:delete',
                'extension_mesh_repository_connection:delete',
                'extension_mesh_entitlement:delete',
            ],
            dependencies: [
                'extension_mesh.viewer',
            ],
        },
    },
});
