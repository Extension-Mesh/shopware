import template from './sw-extension-my-extensions-index.html.twig';

const { Component } = Shopware;

Component.override('sw-extension-my-extensions-index', {
    template,

    computed: {
        extensionMeshIsCustomTab() {
            return [
                'sw.extension.my-extensions.registries',
                'sw.extension.my-extensions.repositories',
            ].includes(this.$route.name);
        },
    },
});
