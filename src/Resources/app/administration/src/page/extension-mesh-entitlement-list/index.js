import template from './extension-mesh-entitlement-list.html.twig';
import './extension-mesh-entitlement-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { format } = Shopware.Utils;

Component.register('extension-mesh-entitlement-list', {
    template,
    inject: ['repositoryFactory', 'acl'],
    mixins: [Mixin.getByName('notification')],

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
        entitlementRepository() {
            return this.repositoryFactory.create('extension_mesh_entitlement');
        },
        columns() {
            return [
                { property: 'customerName', label: this.$t('extension-mesh.entitlements.customerName'), primary: true },
                { property: 'customerNumber', label: this.$t('extension-mesh.entitlements.customerNumber') },
                { property: 'customerEmail', label: this.$t('extension-mesh.entitlements.customerEmail') },
                { property: 'productNumber', label: this.$t('extension-mesh.entitlements.product') },
                { property: 'salesChannelName', label: this.$t('extension-mesh.entitlements.salesChannel') },
                { property: 'orderNumber', label: this.$t('extension-mesh.entitlements.order') },
                { property: 'enabled', label: this.$t('extension-mesh.entitlements.status') },
                { property: 'validUntil', label: this.$t('extension-mesh.entitlements.validUntil') },
                { property: 'createdAt', label: this.$t('extension-mesh.entitlements.createdAt') },
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
            return ['enabled', 'disabled', 'expired'].map((value) => ({
                value,
                label: this.$t(`extension-mesh.entitlements.${value}`),
            }));
        },
        orderLinkOptions() {
            return ['linked', 'standalone'].map((value) => ({
                value,
                label: this.$t(`extension-mesh.entitlements.${value}`),
            }));
        },
        listFilters() {
            return [
                {
                    name: 'customer-filter', property: 'customer', type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.customer'),
                    placeholder: this.$t('extension-mesh.entitlements.customerPlaceholder'),
                    labelProperty: 'customerNumber',
                    schema: { entity: 'customer', referenceField: 'id' },
                },
                {
                    name: 'product-filter', property: 'product', type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.product'),
                    placeholder: this.$t('extension-mesh.entitlements.productPlaceholder'),
                    criteria: this.productCriteria, displayVariants: true,
                    schema: { entity: 'product', referenceField: 'id' },
                },
                {
                    name: 'sales-channel-filter', property: 'salesChannel', type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.salesChannel'),
                    placeholder: this.$t('extension-mesh.entitlements.salesChannelPlaceholder'),
                    schema: { entity: 'sales_channel', referenceField: 'id' },
                },
                {
                    name: 'status-filter', property: 'entitlementStatus', type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.status'),
                    placeholder: this.$t('extension-mesh.entitlements.statusPlaceholder'),
                    options: this.statusOptions, labelProperty: 'label', valueProperty: 'value',
                },
                {
                    name: 'order-link-filter', property: 'orderLink', type: 'multi-select-filter',
                    label: this.$t('extension-mesh.entitlements.orderLink'),
                    placeholder: this.$t('extension-mesh.entitlements.orderLinkPlaceholder'),
                    options: this.orderLinkOptions, labelProperty: 'label', valueProperty: 'value',
                },
            ];
        },
        activeFilterNumber() { return this.filterCriteria.length; },
        hasActiveQuery() { return Boolean(this.term || this.activeFilterNumber); },
    },

    created() { this.initializeFilters(); },

    methods: {
        async initializeFilters() {
            try {
                const stored = await Shopware.Service('filterService').getStoredFilters(this.storeKey);
                this.filterCriteria = Object.values(stored).flatMap((filter) => filter.criteria || []);
            } catch {
                this.filterCriteria = [];
            }
            await this.loadEntitlements();
        },
        buildCriteria() {
            const criteria = new Criteria(this.page, this.limit);
            ['customer', 'product', 'salesChannel', 'order'].forEach((association) => criteria.addAssociation(association));
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.setTotalCountMode(1);

            this.filterCriteria
                .filter((filter) => !['entitlementStatus', 'orderLink'].includes(filter.field))
                .forEach((filter) => criteria.addFilter(filter));

            const statuses = this.filterValues('entitlementStatus');
            if (statuses?.length && statuses.length < 3) {
                const now = new Date().toISOString();
                const filters = [];
                if (statuses.includes('enabled')) {
                    filters.push(Criteria.multi('AND', [
                        Criteria.equals('enabled', true),
                        Criteria.multi('OR', [Criteria.equals('validUntil', null), Criteria.range('validUntil', { gt: now })]),
                    ]));
                }
                if (statuses.includes('disabled')) filters.push(Criteria.equals('enabled', false));
                if (statuses.includes('expired')) {
                    filters.push(Criteria.multi('AND', [
                        Criteria.equals('enabled', true), Criteria.range('validUntil', { lte: now }),
                    ]));
                }
                criteria.addFilter(Criteria.multi('OR', filters));
            }

            const orderLinks = this.filterValues('orderLink');
            if (orderLinks?.length === 1) {
                criteria.addFilter(orderLinks[0] === 'linked'
                    ? Criteria.not('AND', [Criteria.equals('orderId', null)])
                    : Criteria.equals('orderId', null));
            }
            if (this.term) {
                criteria.addFilter(Criteria.multi('OR', [
                    Criteria.contains('customer.customerNumber', this.term),
                    Criteria.contains('customer.firstName', this.term),
                    Criteria.contains('customer.lastName', this.term),
                    Criteria.contains('customer.email', this.term),
                    Criteria.contains('product.productNumber', this.term),
                    Criteria.contains('salesChannel.name', this.term),
                    Criteria.contains('order.orderNumber', this.term),
                ]));
            }
            return criteria;
        },
        async loadEntitlements() {
            this.isLoading = true;
            try {
                const result = await this.entitlementRepository.search(this.buildCriteria(), Shopware.Context.api);
                this.entitlements = result;
                this.total = result.total;
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },
        async onPageChange({ page, limit }) { this.page = page; this.limit = limit; await this.loadEntitlements(); },
        async onSearch(term) { this.term = term; this.page = 1; await this.loadEntitlements(); },
        async updateCriteria(criteria) { this.filterCriteria = criteria; this.page = 1; await this.loadEntitlements(); },
        filterValues(field) {
            const filter = this.filterCriteria.find((item) => item.field === field);
            if (!filter) return undefined;
            return Array.isArray(filter.value) ? filter.value : [filter.value];
        },
        confirmDelete(id) { this.deleteId = id; },
        cancelDelete() { this.deleteId = null; },
        async deleteEntitlement() {
            if (!this.deleteId) return;
            this.isLoading = true;
            try {
                await this.entitlementRepository.delete(this.deleteId, Shopware.Context.api);
                this.deleteId = null;
                await this.loadEntitlements();
                this.createNotificationSuccess({ message: this.$t('extension-mesh.entitlements.deleteSuccess') });
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },
        formatDate(date) { return format.date(date, { month: 'numeric', hour: '2-digit', minute: '2-digit' }); },
        isExpired(entitlement) { return entitlement.validUntil && new Date(entitlement.validUntil) <= new Date(); },
        statusLabel(entitlement) {
            if (!entitlement.enabled) return this.$t('extension-mesh.entitlements.disabled');
            if (this.isExpired(entitlement)) return this.$t('extension-mesh.entitlements.expired');
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
