import template from './extension-mesh-entitlement-list.html.twig';
import './extension-mesh-entitlement-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
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
            term: '',
            filterCriteria: [],
            defaultFilters: [
                'customer-filter',
                'product-filter',
                'sales-channel-filter',
                'status-filter',
                'order-link-filter',
            ],
            storeKey: 'grid.filter.extension-mesh-entitlement',
        };
    },

    computed: {
        columns() {
            return [
                {
                    property: 'customerName',
                    label: this.$t('extension-mesh.entitlements.customerName'),
                    primary: true,
                },
                {
                    property: 'customerNumber',
                    label: this.$t('extension-mesh.entitlements.customerNumber'),
                },
                {
                    property: 'customerEmail',
                    label: this.$t('extension-mesh.entitlements.customerEmail'),
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
                {
                    property: 'createdAt',
                    label: this.$t('extension-mesh.entitlements.createdAt'),
                },
            ];
        },

        productCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('options.group');
            criteria.addFilter(Criteria.multi('OR', [
                Criteria.equals('childCount', 0),
                Criteria.equals('childCount', null),
            ]));
            criteria.setTotalCountMode(0);

            return criteria;
        },

        statusOptions() {
            return [
                {
                    value: 'enabled',
                    label: this.$t('extension-mesh.entitlements.enabled'),
                },
                {
                    value: 'disabled',
                    label: this.$t('extension-mesh.entitlements.disabled'),
                },
                {
                    value: 'expired',
                    label: this.$t('extension-mesh.entitlements.expired'),
                },
            ];
        },

        orderLinkOptions() {
            return [
                {
                    value: 'linked',
                    label: this.$t('extension-mesh.entitlements.linked'),
                },
                {
                    value: 'standalone',
                    label: this.$t('extension-mesh.entitlements.standalone'),
                },
            ];
        },

        listFilters() {
            return [
                {
                    name: 'customer-filter',
                    property: 'customer',
                    type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.customer'),
                    placeholder: this.$t('extension-mesh.entitlements.customerPlaceholder'),
                    labelProperty: 'customerNumber',
                    schema: {
                        entity: 'customer',
                        referenceField: 'id',
                    },
                },
                {
                    name: 'product-filter',
                    property: 'product',
                    type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.product'),
                    placeholder: this.$t('extension-mesh.entitlements.productPlaceholder'),
                    criteria: this.productCriteria,
                    displayVariants: true,
                    schema: {
                        entity: 'product',
                        referenceField: 'id',
                    },
                },
                {
                    name: 'sales-channel-filter',
                    property: 'salesChannel',
                    type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.salesChannel'),
                    placeholder: this.$t('extension-mesh.entitlements.salesChannelPlaceholder'),
                    schema: {
                        entity: 'sales_channel',
                        referenceField: 'id',
                    },
                },
                {
                    name: 'status-filter',
                    property: 'entitlementStatus',
                    type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.status'),
                    placeholder: this.$t('extension-mesh.entitlements.statusPlaceholder'),
                    options: this.statusOptions,
                    labelProperty: 'label',
                    valueProperty: 'value',
                },
                {
                    name: 'order-link-filter',
                    property: 'orderLink',
                    type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.orderLink'),
                    placeholder: this.$t('extension-mesh.entitlements.orderLinkPlaceholder'),
                    options: this.orderLinkOptions,
                    labelProperty: 'label',
                    valueProperty: 'value',
                },
            ];
        },

        activeFilterNumber() {
            return this.filterCriteria.length;
        },

        activeFilters() {
            return {
                search: this.term || undefined,
                customerId: this.filterValues('customer.id'),
                productId: this.filterValues('product.id'),
                salesChannelId: this.filterValues('salesChannel.id'),
                status: this.filterValues('entitlementStatus'),
                orderLink: this.filterValues('orderLink'),
            };
        },

        hasActiveQuery() {
            return Boolean(this.term || this.activeFilterNumber);
        },
    },

    created() {
        this.initializeFilters();
    },

    methods: {
        async initializeFilters() {
            try {
                const storedFilters = await Shopware.Service('filterService')
                    .getStoredFilters(this.storeKey);

                this.filterCriteria = Object.values(storedFilters)
                    .flatMap((filter) => filter.criteria || []);
            } catch {
                this.filterCriteria = [];
            }

            await this.loadEntitlements();
        },

        async loadEntitlements() {
            this.isLoading = true;
            try {
                const result = await this.extensionMeshApiService.getEntitlements(
                    this.page,
                    this.limit,
                    this.activeFilters,
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

        async onSearch(term) {
            this.term = term;
            this.page = 1;
            await this.loadEntitlements();
        },

        async updateCriteria(criteria) {
            this.filterCriteria = criteria;
            this.page = 1;
            await this.loadEntitlements();
        },

        filterValues(field) {
            const criterion = this.filterCriteria.find((filter) => filter.field === field);
            if (!criterion) {
                return undefined;
            }

            return Array.isArray(criterion.value) ? criterion.value : [criterion.value];
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
