const { Component } = Shopware;

Component.override('sw-extension-my-extensions-listing', {
    inject: ['extensionMeshApiService'],

    data() {
        return {
            extensionMeshEntries: [],
            extensionMeshWarnings: [],
        };
    },

    computed: {
        myExtensions() {
            const nativeExtensions = this.$super('myExtensions') || [];
            const registryByName = new Map(
                this.extensionMeshEntries.map((extension) => [extension.name, extension]),
            );

            const merged = nativeExtensions.map((nativeExtension) => {
                const registryExtension = registryByName.get(nativeExtension.name);
                if (!registryExtension) {
                    return nativeExtension;
                }

                registryByName.delete(nativeExtension.name);
                if (nativeExtension.managedByComposer || !registryExtension.extensionMesh.owned) {
                    return nativeExtension;
                }

                const updateAvailable = this.extensionMeshVersionIsNewer(
                    registryExtension.latestVersion,
                    nativeExtension.version,
                );

                return {
                    ...nativeExtension,
                    latestVersion: updateAvailable
                        ? registryExtension.latestVersion
                        : nativeExtension.latestVersion,
                    allowUpdate: updateAvailable,
                    extensionMesh: registryExtension.extensionMesh,
                };
            });

            return [
                ...merged,
                ...registryByName.values(),
            ];
        },
    },

    beforeUnmount() {
        Shopware.Utils.EventBus.off('extension-mesh-registries-changed', this.loadExtensionMeshEntries);
    },

    methods: {
        mountedComponent() {
            this.$super('mountedComponent');
            Shopware.Utils.EventBus.on('extension-mesh-registries-changed', this.loadExtensionMeshEntries);
            this.loadExtensionMeshEntries();
        },

        updateList() {
            this.$super('updateList');
            this.loadExtensionMeshEntries();
        },

        async loadExtensionMeshEntries() {
            const locale = Shopware.Store.get('session').currentLocale || 'en-GB';

            try {
                const catalog = await this.extensionMeshApiService.getExtensions(locale);
                this.extensionMeshEntries = catalog.extensions || [];
                this.extensionMeshWarnings = catalog.warnings || [];
            } catch {
                this.extensionMeshEntries = [];
            }
        },

        extensionMeshVersionIsNewer(candidate, installed) {
            if (!candidate || !installed) {
                return false;
            }

            const parse = (version) => {
                const match = String(version).trim().replace(/^v/, '').match(
                    /^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/,
                );

                if (!match) {
                    return null;
                }

                return {
                    core: match.slice(1, 5).map((part) => Number(part || 0)),
                    preRelease: match[5]?.split('.') || [],
                };
            };
            const left = parse(candidate);
            const right = parse(installed);

            if (!left || !right) {
                return false;
            }

            for (let index = 0; index < left.core.length; index += 1) {
                if (left.core[index] !== right.core[index]) {
                    return left.core[index] > right.core[index];
                }
            }

            if (left.preRelease.length === 0 || right.preRelease.length === 0) {
                return left.preRelease.length === 0 && right.preRelease.length > 0;
            }

            for (let index = 0; index < Math.max(left.preRelease.length, right.preRelease.length); index += 1) {
                const leftPart = left.preRelease[index];
                const rightPart = right.preRelease[index];

                if (leftPart === undefined || rightPart === undefined) {
                    return rightPart === undefined;
                }

                if (leftPart === rightPart) {
                    continue;
                }

                const leftNumeric = /^\d+$/.test(leftPart);
                const rightNumeric = /^\d+$/.test(rightPart);
                if (leftNumeric && rightNumeric) {
                    return Number(leftPart) > Number(rightPart);
                }

                if (leftNumeric !== rightNumeric) {
                    return !leftNumeric;
                }

                return leftPart > rightPart;
            }

            return false;
        },
    },
});
