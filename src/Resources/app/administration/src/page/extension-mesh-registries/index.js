import template from './extension-mesh-registries.html.twig';
import './extension-mesh-registries.scss';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('extension-mesh-registries', {
    template,

    inject: ['extensionMeshApiService', 'repositoryFactory'],

    data() {
        return {
            isLoading: false,
            error: null,
            registryUrl: '',
            accessToken: '',
            registries: [],
            credentialRegistryId: null,
            credentialToken: '',
        };
    },

    created() {
        this.loadRegistries();
    },

    computed: {
        registryRepository() {
            return this.repositoryFactory.create('extension_mesh_registry_source');
        },
    },

    methods: {
        async loadRegistries() {
            this.isLoading = true;
            this.error = null;

            try {
                const criteria = new Criteria(1, 500);
                criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
                const result = await this.registryRepository.search(criteria, Shopware.Context.api);
                this.registries = result.map((registry) => ({
                    ...registry,
                    hasCredential: Boolean(registry.credentialFingerprint),
                }));
            } catch (error) {
                this.error = this.errorMessage(error);
            } finally {
                this.isLoading = false;
            }
        },

        async addRegistry() {
            if (!this.registryUrl.trim()) {
                return;
            }

            await this.withLoading(async () => {
                await this.extensionMeshApiService.addRegistry(
                    this.registryUrl.trim(),
                    this.accessToken.trim() || null,
                );
                this.registryUrl = '';
                this.accessToken = '';
                await this.loadRegistries();
                Shopware.Utils.EventBus.emit('extension-mesh-registries-changed');
            });
        },

        editCredential(id) {
            this.credentialRegistryId = id;
            this.credentialToken = '';
        },

        cancelCredential() {
            this.credentialRegistryId = null;
            this.credentialToken = '';
        },

        async saveCredential(id) {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.updateRegistryCredential(
                    id,
                    this.credentialToken.trim() || null,
                );
                this.cancelCredential();
                await this.loadRegistries();
                Shopware.Utils.EventBus.emit('extension-mesh-registries-changed');
            });
        },

        async removeRegistry(id) {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.removeRegistry(id);
                await this.loadRegistries();
                Shopware.Utils.EventBus.emit('extension-mesh-registries-changed');
            });
        },

        async refreshRegistries() {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.refreshRegistries();
                await this.loadRegistries();
                Shopware.Utils.EventBus.emit('extension-mesh-registries-changed');
            });
        },

        async withLoading(callback) {
            this.isLoading = true;
            this.error = null;
            try {
                await callback();
            } catch (error) {
                this.error = this.errorMessage(error);
            } finally {
                this.isLoading = false;
            }
        },

        errorMessage(error) {
            return error?.response?.data?.errors?.[0]?.detail
                || this.$t('extension-mesh.registries.unknownError');
        },
    },
});
