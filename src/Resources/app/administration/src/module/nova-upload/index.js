import './page/nova-upload-list';
import './component/nova-upload-files';
import './nova-upload.scss';

/**
 * Bestellungen → Uploads: alle hochgeladenen Dateien aus Kontaktformular und Artikeln.
 */
Shopware.Module.register('nova-upload', {
    type: 'plugin',
    name: 'nova-upload',
    title: 'nova-upload.general.title',
    description: 'nova-upload.general.description',
    color: '#a092f0',
    icon: 'regular-file',

    routes: {
        index: {
            component: 'nova-upload-list',
            path: 'index',
            meta: { privilege: 'order.viewer' },
        },
    },

    navigation: [{
        id: 'nova-upload',
        label: 'nova-upload.general.title',
        path: 'nova.upload.index',
        parent: 'sw-order',
        privilege: 'order.viewer',
        position: 100,
    }],
});
