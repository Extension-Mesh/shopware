import template from './extension-mesh-repositories.html.twig';
import './extension-mesh-repositories.scss';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('extension-mesh-repositories', {
    template,

    inject: ['extensionMeshApiService', 'repositoryFactory'],

    data() {
        return {
            isLoading: false,
            error: null,
            repositories: [],
            repositoryPage: 1,
            repositoryLimit: 10,
            repositoryTotal: 0,
            providers: [],
            publication: [],
            publicationPage: 1,
            publicationLimit: 10,
            publicationTotal: 0,
            publicationPaths: null,
            provider: 'github',
            repositoryName: '',
            apiBaseUrl: 'https://api.github.com',
            showCustomApiBaseUrl: false,
            repositoryToken: '',
            mode: 'import',
            productId: null,
            credentialRepositoryId: null,
            credentialToken: '',
            repositoryPollTimer: null,
        };
    },

    computed: {
        repositoryRepository() {
            return this.repositoryFactory.create('extension_mesh_repository_connection');
        },

        publicationRepository() {
            return this.repositoryFactory.create('extension_mesh_published_release');
        },

        modeOptions() {
            return [
                {
                    value: 'import',
                    label: this.$t('extension-mesh.repositories.modeImport'),
                },
                {
                    value: 'link',
                    label: this.$t('extension-mesh.repositories.modeLink'),
                },
            ];
        },

        providerOptions() {
            return this.providers.map((provider) => ({
                value: provider.key,
                label: provider.label,
            }));
        },

        repositoryColumns() {
            return [
                {
                    property: 'repository',
                    label: this.$t('extension-mesh.repositories.repositoryLabel'),
                    primary: true,
                },
                {
                    property: 'technicalName',
                    label: this.$t('extension-mesh.repositories.extensionLabel'),
                },
                {
                    property: 'productId',
                    label: this.$t('extension-mesh.repositories.productColumnLabel'),
                },
                {
                    property: 'lastSyncedAt',
                    label: this.$t('extension-mesh.repositories.statusLabel'),
                },
            ];
        },

        publicationColumns() {
            return [
                {
                    property: 'technicalName',
                    label: this.$t('extension-mesh.repositories.extensionLabel'),
                    primary: true,
                },
                {
                    property: 'version',
                    label: this.$t('extension-mesh.publication.versionLabel'),
                },
                {
                    property: 'productId',
                    label: this.$t('extension-mesh.repositories.productColumnLabel'),
                },
                {
                    property: 'validationError',
                    label: this.$t('extension-mesh.repositories.statusLabel'),
                },
            ];
        },

        githubTokenUrl() {
            const parameters = new URLSearchParams({
                name: 'ExtensionMesh',
                description: 'Read-only repository access for ExtensionMesh synchronization',
                expires_in: '90',
                contents: 'read',
            });
            const owner = this.repositoryName.trim().match(/^([A-Za-z0-9_.-]{1,100})\//)?.[1];
            if (owner) {
                parameters.set('target_name', owner);
            }

            return `https://github.com/settings/personal-access-tokens/new?${parameters.toString()}`;
        },

        isGithubProvider() {
            return this.provider === 'github';
        },

        showApiBaseUrl() {
            return !this.isGithubProvider || this.showCustomApiBaseUrl;
        },
    },

    created() {
        this.loadRepositories();
    },

    beforeUnmount() {
        this.clearRepositoryPoll();
    },

    methods: {
        async loadRepositories() {
            this.isLoading = true;
            this.error = null;

            try {
                await this.extensionMeshApiService.synchronizePublications();
                const [repositories, publication, providers] = await Promise.all([
                    this.fetchRepositoryPage(),
                    this.fetchPublicationPage(),
                    this.extensionMeshApiService.getRepositoryProviders(),
                ]);
                this.applyRepositoryPage(repositories);
                this.providers = providers;
                this.applyPublicationPage(publication);
                this.publicationPaths = {
                    registry: '/extension-mesh/v1/registry',
                    account: '/account/extension-mesh',
                };
                if (!this.providers.some((provider) => provider.key === this.provider)) {
                    this.provider = this.providers[0]?.key || '';
                }
                if (!this.apiBaseUrl) {
                    this.setApiBaseUrl();
                }
            } catch (error) {
                this.error = this.errorMessage(error);
            } finally {
                this.isLoading = false;
            }
        },

        async loadRepositoryPage() {
            await this.withLoading(async () => {
                const result = await this.fetchRepositoryPage();
                this.applyRepositoryPage(result);
            });
        },

        async loadPublicationPage() {
            await this.withLoading(async () => {
                const result = await this.fetchPublicationPage();
                this.applyPublicationPage(result);
            });
        },

        async fetchRepositoryPage() {
            const criteria = new Criteria(this.repositoryPage, this.repositoryLimit);
            criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
            criteria.setTotalCountMode(1);
            const result = await this.repositoryRepository.search(criteria, Shopware.Context.api);
            return { data: result, total: result.total, page: this.repositoryPage, limit: this.repositoryLimit };
        },

        async fetchPublicationPage() {
            const criteria = new Criteria(this.publicationPage, this.publicationLimit);
            criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
            criteria.setTotalCountMode(1);
            const result = await this.publicationRepository.search(criteria, Shopware.Context.api);
            return { data: result, total: result.total, page: this.publicationPage, limit: this.publicationLimit };
        },

        applyRepositoryPage(result) {
            this.repositories = result.data;
            this.repositoryTotal = result.total;
            this.repositoryPage = result.page;
            this.repositoryLimit = result.limit;
            this.scheduleRepositoryPoll();
        },

        applyPublicationPage(result) {
            this.publication = result.data;
            this.publicationTotal = result.total;
            this.publicationPage = result.page;
            this.publicationLimit = result.limit;
        },

        async onRepositoryPageChange({ page, limit }) {
            this.repositoryPage = page;
            this.repositoryLimit = limit;
            await this.loadRepositoryPage();
        },

        async onPublicationPageChange({ page, limit }) {
            this.publicationPage = page;
            this.publicationLimit = limit;
            await this.loadPublicationPage();
        },

        async connectRepository() {
            if (!this.repositoryName.trim() || (this.mode === 'link' && !this.productId)) {
                return;
            }

            await this.withLoading(async () => {
                await this.extensionMeshApiService.connectSellerRepository({
                    provider: this.provider,
                    repository: this.repositoryName.trim(),
                    apiBaseUrl: this.apiBaseUrl.trim()
                        || this.selectedProvider()?.defaultApiBaseUrl
                        || '',
                    accessToken: this.repositoryToken.trim(),
                    mode: this.mode,
                    productId: this.mode === 'link' ? this.productId : null,
                });
                this.resetForm();
                this.repositoryPage = 1;
                await this.loadRepositories();
            });
        },

        async syncRepository(id) {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.syncSellerRepository(id);
                await this.loadRepositoryPage();
            });
        },

        repositoryStatusLabel(repository) {
            const status = repository.onboardingStatus || 'ready';

            return this.$t(`extension-mesh.repositories.processingStatus.${status}`);
        },

        scheduleRepositoryPoll() {
            this.clearRepositoryPoll();
            if (!this.repositories.some((repository) => [
                'queued',
                'inspecting',
                'preparing',
                'synchronizing',
            ].includes(repository.onboardingStatus))) {
                return;
            }

            this.repositoryPollTimer = window.setTimeout(
                () => this.pollRepositoryProgress(),
                2500,
            );
        },

        clearRepositoryPoll() {
            if (this.repositoryPollTimer !== null) {
                window.clearTimeout(this.repositoryPollTimer);
                this.repositoryPollTimer = null;
            }
        },

        async pollRepositoryProgress() {
            this.repositoryPollTimer = null;
            try {
                const [repositories, publication] = await Promise.all([
                    this.fetchRepositoryPage(),
                    this.fetchPublicationPage(),
                ]);
                this.applyRepositoryPage(repositories);
                this.applyPublicationPage(publication);
            } catch (error) {
                this.error = this.errorMessage(error);
                this.scheduleRepositoryPoll();
            }
        },

        editCredential(id) {
            this.credentialRepositoryId = id;
            this.credentialToken = '';
        },

        cancelCredential() {
            this.credentialRepositoryId = null;
            this.credentialToken = '';
        },

        async saveCredential(id) {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.updateSellerRepositoryCredential(
                    id,
                    this.credentialToken.trim(),
                );
                this.cancelCredential();
                await this.loadRepositories();
            });
        },

        async unlinkRepository(id) {
            await this.withLoading(async () => {
                await this.extensionMeshApiService.unlinkSellerRepository(id);
                const result = await this.fetchRepositoryPage();
                this.applyRepositoryPage(result);
            });
        },

        resetForm() {
            this.provider = this.providers[0]?.key || 'github';
            this.repositoryName = '';
            this.repositoryToken = '';
            this.mode = 'import';
            this.productId = null;
            this.showCustomApiBaseUrl = false;
            this.setApiBaseUrl();
            this.cancelCredential();
        },

        selectedProvider() {
            return this.providers.find((provider) => provider.key === this.provider);
        },

        setApiBaseUrl(providerKey = this.provider) {
            this.showCustomApiBaseUrl = false;
            this.apiBaseUrl = this.providers.find(
                (provider) => provider.key === providerKey,
            )?.defaultApiBaseUrl || '';
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
