import template from './extension-mesh-entitlement-create.html.twig';
import './extension-mesh-entitlement-create.scss';

const { Component, Mixin } = Shopware;

Component.register('extension-mesh-entitlement-create', {
    template,

    inject: ['extensionMeshApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            savedId: null,
            form: {
                customerId: null,
                productId: null,
                salesChannelId: null,
                orderId: null,
                enabled: true,
                validUntil: null,
            },
        };
    },

    computed: {
        canSave() {
            return !this.isLoading
                && this.form.customerId
                && this.form.productId
                && this.form.salesChannelId;
        },
    },

    methods: {
        async saveEntitlement() {
            if (!this.canSave) {
                return;
            }

            this.isLoading = true;
            try {
                const entitlement = await this.extensionMeshApiService.createEntitlement({
                    ...this.form,
                    orderId: this.form.orderId || null,
                });
                this.savedId = entitlement.id;
                this.isSaveSuccessful = true;
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.errors?.[0]?.detail
                        || this.$t('extension-mesh.entitlements.unknownError'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveFinish() {
            this.isSaveSuccessful = false;
            this.$router.replace({
                name: 'extension.mesh.entitlement.detail',
                params: { id: this.savedId },
            });
        },
    },
});
