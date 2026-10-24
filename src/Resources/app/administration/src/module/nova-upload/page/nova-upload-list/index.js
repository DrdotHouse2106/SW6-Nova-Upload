import template from './nova-upload-list.html.twig';
import api from '../../service/upload-api';

const { Component, Mixin } = Shopware;

/**
 * Liste aller Uploads mit Suche (Dateiname, Kunde, Betreff/Artikel, Bestellnummer) und Filter nach Herkunft.
 */
Component.register('nova-upload-list', {
    template,

    inject: ['acl', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            items: [],
            total: 0,
            page: 1,
            limit: 25,
            term: this.$route?.query?.term || '',
            source: '',
            salesChannels: {},
            deleteItem: null,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        pages() {
            return Math.max(1, Math.ceil(this.total / this.limit));
        },
    },

    created() {
        this.loadSalesChannels();
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;
            try {
                const data = await api.list({ term: this.term, source: this.source, page: this.page, limit: this.limit });
                this.items = data.items;
                this.total = data.total;
            } catch (error) {
                this.createNotificationError({ message: error?.response?.data?.message || this.$tc('nova-upload.list.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        async loadSalesChannels() {
            try {
                const result = await this.repositoryFactory.create('sales_channel').search(new Shopware.Data.Criteria(1, 100));
                const names = {};
                result.forEach((channel) => { names[channel.id] = channel.translated?.name || channel.name; });
                this.salesChannels = names;
            } catch {
                this.salesChannels = {};
            }
        },

        onSearch() {
            window.clearTimeout(this.searchTimer);
            this.searchTimer = window.setTimeout(() => {
                this.page = 1;
                this.load();
            }, 300);
        },

        onFilter() {
            this.page = 1;
            this.load();
        },

        goTo(page) {
            this.page = Math.min(this.pages, Math.max(1, page));
            this.load();
        },

        async download(item) {
            try {
                await api.download(item);
            } catch {
                this.createNotificationError({ message: this.$tc('nova-upload.list.downloadError') });
            }
        },

        async confirmDelete() {
            const item = this.deleteItem;
            this.deleteItem = null;
            try {
                await api.remove(item);
                this.createNotificationSuccess({ message: this.$tc('nova-upload.list.deleted') });
                await this.load();
            } catch (error) {
                this.createNotificationError({ message: error?.response?.data?.message || this.$tc('nova-upload.list.deleteError') });
            }
        },

        openOrder(item) {
            this.$router.push({ name: 'sw.order.detail', params: { id: item.orderId } });
        },

        size(item) {
            return api.formatSize(item.size);
        },

        date(item) {
            return Shopware.Utils.format.date(item.createdAt, { hour: '2-digit', minute: '2-digit' });
        },
    },
});
