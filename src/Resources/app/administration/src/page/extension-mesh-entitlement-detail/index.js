import template from './extension-mesh-entitlement-detail.html.twig';
import './extension-mesh-entitlement-detail.scss';

const { Component, Mixin } = Shopware;

Component.register('extension-mesh-entitlement-detail', {
    template,
    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],
    props: {
        entitlementId: { type: String, required: true },
    },
    data() {
        return { isLoading: false, isSaveSuccessful: false, form: null };
    },
    computed: {
        canSave() {
            return !this.isLoading
                && this.form?.customerId
                && this.form?.productId
                && this.form?.salesChannelId;
        },
    },
    created() { this.loadEntitlement(); },
    methods: {
        async loadEntitlement() {
            this.isLoading = true;
            try {
                this.form = await this.repositoryFactory
                    .create('extension_mesh_entitlement')
                    .get(this.entitlementId, Shopware.Context.api);
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },
        async saveEntitlement() {
            if (!this.canSave) return;
            this.isLoading = true;
            try {
                await this.repositoryFactory
                    .create('extension_mesh_entitlement')
                    .save(this.form, Shopware.Context.api);
                this.isSaveSuccessful = true;
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isLoading = false;
            }
        },
        saveFinish() { this.isSaveSuccessful = false; },
        notifyError(error) {
            this.createNotificationError({
                message: error?.response?.data?.errors?.[0]?.detail
                    || this.$t('extension-mesh.entitlements.unknownError'),
            });
        },
    },
});
