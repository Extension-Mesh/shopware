const { Component } = Shopware;

Component.override('sw-product-detail', {
    inject: ['extensionMeshApiService'],

    data() {
        return {
            extensionMeshManagedProduct: false,
        };
    },

    computed: {
        productCriteria() {
            const criteria = this.$super('productCriteria');

            if (this.extensionMeshManagedProduct) {
                criteria.getAssociation('downloads').setLimit(1);
            }

            return criteria;
        },
    },

    methods: {
        async loadProduct() {
            const productId = this.productId || this.product?.id;

            if (productId) {
                try {
                    const status = await this.extensionMeshApiService.getProductIntegration(productId);
                    this.extensionMeshManagedProduct = status.enabled;
                } catch {
                    this.extensionMeshManagedProduct = false;
                }
            }

            return this.$super('loadProduct');
        },
    },
});
