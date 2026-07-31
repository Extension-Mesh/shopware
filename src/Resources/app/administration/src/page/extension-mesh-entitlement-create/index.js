import template from './extension-mesh-entitlement-create.html.twig';
import './extension-mesh-entitlement-create.scss';

const { Component, Mixin } = Shopware;

Component.register('extension-mesh-entitlement-create', {
    template,
    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            savedId: null,
            form: this.repositoryFactory
                .create('extension_mesh_entitlement')
                .create(Shopware.Context.api),
        };
    },

    created() {
        this.form.enabled = true;
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
            if (!this.canSave) return;
            this.isLoading = true;
            try {
                await this.repositoryFactory
                    .create('extension_mesh_entitlement')
                    .save(this.form, Shopware.Context.api);
                this.savedId = this.form.id;
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
