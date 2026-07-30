const { Component } = Shopware;

Component.override('sw-self-maintained-extension-card', {
    inject: ['extensionMeshApiService'],

    methods: {
        async installExtension() {
            if (!this.extension.extensionMesh) {
                return this.$super('installExtension');
            }

            this.isLoading = true;
            try {
                await this.prepareExtensionMeshArtifact();
            } catch (error) {
                this.showExtensionErrors(error);
                this.isLoading = false;

                return;
            }

            this.isLoading = false;

            return this.$super('installExtension');
        },

        async installAndActivateExtension() {
            if (!this.extension.extensionMesh) {
                return this.$super('installAndActivateExtension');
            }

            this.isLoading = true;
            try {
                await this.prepareExtensionMeshArtifact();
            } catch (error) {
                this.showExtensionErrors(error);
                this.isLoading = false;

                return;
            }

            this.isLoading = false;

            return this.$super('installAndActivateExtension');
        },

        async updateExtension(allowNewPermissions = false) {
            if (!this.extension.extensionMesh) {
                return this.$super('updateExtension', allowNewPermissions);
            }

            this.isLoading = true;
            try {
                await this.prepareExtensionMeshArtifact();
            } catch (error) {
                this.showExtensionErrors(error);
                this.isLoading = false;

                return;
            }

            this.isLoading = false;

            return this.$super('updateExtension', allowNewPermissions);
        },

        prepareExtensionMeshArtifact() {
            if (this.extension.extensionMesh.conflict) {
                throw new Error('ExtensionMesh registry conflict');
            }

            return this.extensionMeshApiService.prepareExtension(
                this.extension.extensionMesh.registryId,
                this.extension.name,
            );
        },
    },
});
