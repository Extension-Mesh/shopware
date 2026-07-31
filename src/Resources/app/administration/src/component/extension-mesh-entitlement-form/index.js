import template from './extension-mesh-entitlement-form.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;
const { format } = Shopware.Utils;

Component.register('extension-mesh-entitlement-form', {
    template,

    inject: ['extensionMeshApiService'],

    props: {
        form: {
            type: Object,
            required: true,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            eligibleProductIds: [],
            isLoadingOptions: true,
        };
    },

    computed: {
        customerCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('defaultBillingAddress.country');
            criteria.setTotalCountMode(0);

            return criteria;
        },

        productCriteriaFilters() {
            return [
                Criteria.multi('OR', [
                    Criteria.equals('childCount', 0),
                    Criteria.equals('childCount', null),
                ]),
                Criteria.equalsAny(
                    'id',
                    this.eligibleProductIds.length
                        ? this.eligibleProductIds
                        : ['00000000000000000000000000000000'],
                ),
            ];
        },

        productCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('options.group');
            criteria.addAssociation('parent');
            this.productCriteriaFilters.forEach((filter) => criteria.addFilter(filter));
            criteria.setTotalCountMode(0);

            return criteria;
        },

        productContext() {
            return {
                ...Shopware.Context.api,
                inheritance: true,
            };
        },

        productAdvancedSelectionParameters() {
            return {
                criteriaFilters: this.productCriteriaFilters,
            };
        },

        orderCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addAssociation('orderCustomer');
            criteria.addFilter(Criteria.equals('orderCustomer.customerId', this.form.customerId));
            criteria.addFilter(Criteria.equals('salesChannelId', this.form.salesChannelId));
            criteria.addSorting(Criteria.sort('orderDateTime', 'DESC'));
            criteria.setTotalCountMode(0);

            return criteria;
        },

        orderSelectionDisabled() {
            return this.disabled || !this.form.customerId || !this.form.salesChannelId;
        },
    },

    watch: {
        'form.customerId'(value, previous) {
            if (previous && value !== previous) {
                this.form.orderId = null;
            }
        },

        'form.salesChannelId'(value, previous) {
            if (previous && value !== previous) {
                this.form.orderId = null;
            }
        },
    },

    created() {
        this.loadOptions();
    },

    methods: {
        async loadOptions() {
            this.isLoadingOptions = true;
            try {
                const options = await this.extensionMeshApiService.getEntitlementOptions();
                this.eligibleProductIds = options.eligibleProductIds;
            } finally {
                this.isLoadingOptions = false;
            }
        },

        customerName(customer) {
            return [
                customer.firstName || customer.translated?.firstName,
                customer.lastName || customer.translated?.lastName,
            ].filter(Boolean).join(' ');
        },

        orderCustomerName(order) {
            return [
                order.orderCustomer?.firstName,
                order.orderCustomer?.lastName,
            ].filter(Boolean).join(' ');
        },

        formatOrderDate(date) {
            return format.date(date, {
                month: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        },
    },
});
