import template from './sw-product-download-form.html.twig';
import './sw-product-download-form.scss';

const { Component, Context, Data } = Shopware;
const { Criteria } = Data;
const { format } = Shopware.Utils;

Component.override('sw-product-download-form', {
    template,

    inject: ['extensionMeshApiService'],

    data() {
        return {
            extensionMeshStatusLoaded: false,
            extensionMeshEnabled: false,
            extensionMeshSource: null,
            extensionMeshDownloads: [],
            extensionMeshPage: 1,
            extensionMeshLimit: 10,
            extensionMeshTotal: 0,
            extensionMeshLoading: false,
            extensionMeshPollTimer: null,
        };
    },

    computed: {
        extensionMeshColumns() {
            return [
                {
                    property: 'fileName',
                    label: this.$t('extension-mesh.productDownloads.file'),
                    primary: true,
                },
                {
                    property: 'version',
                    label: this.$t('extension-mesh.productDownloads.version'),
                },
                {
                    property: 'shopware',
                    label: this.$t('extension-mesh.productDownloads.shopware'),
                },
                {
                    property: 'publicationStatus',
                    label: this.$t('extension-mesh.productDownloads.status'),
                },
                {
                    property: 'fileSize',
                    label: this.$t('extension-mesh.productDownloads.size'),
                },
                {
                    property: 'createdAt',
                    label: this.$t('extension-mesh.productDownloads.createdAt'),
                },
            ];
        },

        extensionMeshRepositoryManaged() {
            return this.extensionMeshSource === 'repository';
        },
    },

    mounted() {
        this.loadExtensionMeshStatus();
    },

    beforeUnmount() {
        this.clearExtensionMeshPoll();
    },

    methods: {
        async loadExtensionMeshStatus() {
            if (!this.product?.id) {
                this.extensionMeshStatusLoaded = true;
                return;
            }

            try {
                const status = await this.extensionMeshApiService.getProductIntegration(
                    this.product.id,
                );
                this.applyExtensionMeshStatus(status);
                if (status.enabled) {
                    await this.loadExtensionMeshDownloads();
                }
            } catch (error) {
                this.applyExtensionMeshStatus({ enabled: false, source: null });
            } finally {
                this.extensionMeshStatusLoaded = true;
            }
        },

        applyExtensionMeshStatus(status) {
            this.extensionMeshEnabled = status.enabled;
            this.extensionMeshSource = status.source;
        },

        async updateExtensionMeshIntegration(enabled) {
            this.extensionMeshLoading = true;
            try {
                const status = await this.extensionMeshApiService.setProductIntegration(
                    this.product.id,
                    enabled,
                );
                this.applyExtensionMeshStatus(status);
                if (status.enabled) {
                    await this.loadExtensionMeshDownloads();
                } else {
                    this.$router.go(0);
                }
            } catch (error) {
                this.notifyExtensionMeshError(error);
            } finally {
                this.extensionMeshLoading = false;
            }
        },

        async loadExtensionMeshDownloads() {
            if (!this.extensionMeshEnabled) {
                return;
            }

            this.extensionMeshLoading = true;
            try {
                const result = await this.extensionMeshApiService.getProductDownloads(
                    this.product.id,
                    this.extensionMeshPage,
                    this.extensionMeshLimit,
                );
                this.extensionMeshDownloads = result.items;
                this.extensionMeshPage = result.page;
                this.extensionMeshLimit = result.limit;
                this.extensionMeshTotal = result.total;
                if (result.items.some((item) => item.publicationStatus === 'pending')) {
                    this.scheduleExtensionMeshPoll();
                } else {
                    this.clearExtensionMeshPoll();
                }
            } catch (error) {
                this.notifyExtensionMeshError(error);
            } finally {
                this.extensionMeshLoading = false;
            }
        },

        async onExtensionMeshPageChange({ page, limit }) {
            this.extensionMeshPage = page;
            this.extensionMeshLimit = limit;
            await this.loadExtensionMeshDownloads();
        },

        async successfulUpload({ targetId }) {
            if (!this.extensionMeshEnabled) {
                return this.$super('successfulUpload', { targetId });
            }

            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('productId', this.product.id));
            criteria.addFilter(Criteria.equals('mediaId', targetId));
            const existing = await this.productDownloadRepository.search(criteria, Context.api);
            if (existing.total > 0) {
                return;
            }

            const productDownload = this.createDownloadAssociation(targetId);
            productDownload.position = this.extensionMeshTotal;
            await this.productDownloadRepository.save(productDownload, Context.api);
            this.product.downloads.add(productDownload);
            if (this.error) {
                Shopware.Store.get('error').removeApiError(this.error.selfLink);
            }
            this.extensionMeshPage = 1;
            await this.queueExtensionMeshPublication();
        },

        async onRemoveDownload(download) {
            if (!this.extensionMeshEnabled) {
                return this.$super('onRemoveDownload', download);
            }

            this.extensionMeshLoading = true;
            try {
                await this.productDownloadRepository.delete(download.id, Context.api);
                this.product.downloads.remove(download.id);
                await this.queueExtensionMeshPublication();
            } finally {
                this.extensionMeshLoading = false;
            }
        },

        async queueExtensionMeshPublication() {
            await this.extensionMeshApiService.refreshProductPublication(this.product.id);
            await this.loadExtensionMeshDownloads();
            this.scheduleExtensionMeshPoll();
        },

        scheduleExtensionMeshPoll() {
            this.clearExtensionMeshPoll();
            this.extensionMeshPollTimer = window.setTimeout(
                () => this.loadExtensionMeshDownloads(),
                2000,
            );
        },

        clearExtensionMeshPoll() {
            if (this.extensionMeshPollTimer) {
                window.clearTimeout(this.extensionMeshPollTimer);
                this.extensionMeshPollTimer = null;
            }
        },

        extensionMeshFileSize(item) {
            return format.fileSize(item.fileSize);
        },

        extensionMeshDate(item) {
            return format.date(item.createdAt, { month: 'numeric' });
        },

        extensionMeshStatusLabel(item) {
            return this.$t(`extension-mesh.productDownloads.statuses.${item.publicationStatus}`);
        },

        notifyExtensionMeshError(error) {
            this.createNotificationError({
                message: error?.response?.data?.errors?.[0]?.detail
                    || this.$t('extension-mesh.productDownloads.error'),
            });
        },
    },
});
