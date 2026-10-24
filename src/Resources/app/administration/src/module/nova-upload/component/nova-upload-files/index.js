import template from './nova-upload-files.html.twig';
import api from '../../service/upload-api';

const { Component, Mixin } = Shopware;

/**
 * Karte „Hochgeladene Dateien“ in der Bestellung – erscheint nur, wenn es Uploads gibt.
 */
Component.register('nova-upload-files', {
    template,

    mixins: [Mixin.getByName('notification')],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return { items: [] };
    },

    watch: {
        orderId() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            try {
                const data = await api.list({ orderId: this.orderId, limit: 100 });
                this.items = data.items;
            } catch {
                this.items = [];
            }
        },

        async download(item) {
            try {
                await api.download(item);
            } catch {
                this.createNotificationError({ message: this.$tc('nova-upload.list.downloadError') });
            }
        },

        size(item) {
            return api.formatSize(item.size);
        },
    },
});
