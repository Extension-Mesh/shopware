import template from './extension-mesh-entitlement-list.html.twig';
import './extension-mesh-entitlement-list.scss';

const { Component, Mixin } = Shopware;
const { format } = Shopware.Utils;

Component.register('extension-mesh-entitlement-list', {
    template,

    inject: ['extensionMeshApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            entitlements: [],
            page: 1,
            limit: 25,
            total: 0,
            deleteId: null,
        };
    },

    computed: {
        columns() {
            return [
                {
                    property: 'customerName',
                    label: this.$t('extension-mesh.entitlements.customer'),
                    primary: true,
                },
                {
                    property: 'productNumber',
                    label: this.$t('extension-mesh.entitlements.product'),
                },
                {
                    property: 'salesChannelName',
                    label: this.$t('extension-mesh.entitlements.salesChannel'),
                },
                {
                    property: 'orderNumber',
                    label: this.$t('extension-mesh.entitlements.order'),
                },
                {
                    property: 'enabled',
                    label: this.$t('extension-mesh.entitlements.status'),
                },
                {
                    property: 'validUntil',
                    label: this.$t('extension-mesh.entitlements.validUntil'),
                },
            ];
        },
    },

    created() {
        this.loadEntitlements();
    },

    methods: {
        async loadEntitlements() {
            this.isLoading = true;
            try {
                const result = await this.extensionMeshApiService.getEntitlements(
                    this.page,
                    this.limit,
                );
                this.entitlements = result.data;
                this.total = result.total;
                this.page = result.page;
                this.limit = result.limit;
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },

        async onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            await this.loadEntitlements();
        },

        confirmDelete(id) {
            this.deleteId = id;
        },

        cancelDelete() {
            this.deleteId = null;
        },

        async deleteEntitlement() {
            if (!this.deleteId) {
                return;
            }

            this.isLoading = true;
            try {
                await this.extensionMeshApiService.deleteEntitlement(this.deleteId);
                this.deleteId = null;
                await this.loadEntitlements();
                this.createNotificationSuccess({
                    message: this.$t('extension-mesh.entitlements.deleteSuccess'),
                });
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },

        formatDate(date) {
            return format.date(date, {
                month: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        },

        statusLabel(entitlement) {
            if (!entitlement.enabled) {
                return this.$t('extension-mesh.entitlements.disabled');
            }
            if (entitlement.expired) {
                return this.$t('extension-mesh.entitlements.expired');
            }

            return this.$t('extension-mesh.entitlements.enabled');
        },

        notifyError(error) {
            this.createNotificationError({
                message: error?.response?.data?.errors?.[0]?.detail
                    || this.$t('extension-mesh.entitlements.unknownError'),
            });
        },
    },
});
