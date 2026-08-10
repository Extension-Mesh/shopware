import template from './extension-mesh-credential-select.html.twig';

const { Component } = Shopware;

Component.extend('extension-mesh-credential-select', 'sw-single-select', {
    template,

    emits: [
        'new-token',
        'update:value',
        'item-selected',
        'on-open-change',
        'before-selection-clear',
        'search',
        'paginate',
    ],

    props: {
        validateToken: {
            type: Function,
            required: true,
        },

        addLabel: {
            type: String,
            required: true,
        },
    },

    methods: {
        search() {
            this.$emit('search', this.searchTerm);

            this.results = this.searchFunction({
                options: this.options.filter((option) => !option.isNew),
                labelProperty: this.labelProperty,
                valueProperty: this.valueProperty,
                searchTerm: this.searchTerm,
            });

            if (this.validateToken(this.searchTerm)) {
                this.results.unshift({
                    value: '__new__',
                    label: this.addLabel,
                    isNew: true,
                });
            }

            this.$nextTick(() => this.resetActiveItem());
        },

        setValue(item) {
            if (item?.isNew) {
                this.itemRecentlySelected = true;
                this.$emit('new-token', this.searchTerm);
                this.currentValue = '__new__';
                this.closeResultList();
                return;
            }

            this.$emit('new-token', '');
            this.$super('setValue', item);
        },
    },
});
