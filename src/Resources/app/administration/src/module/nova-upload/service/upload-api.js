/**
 * Aufrufe der Admin-API von Nova Upload (Liste, Herunterladen, Löschen).
 */
function http() {
    return Shopware.Application.getContainer('init').httpClient;
}

function headers() {
    const token = Shopware.Service('loginService').getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

export default {
    async list(params) {
        const response = await http().get('_action/nova-upload/list', { params, headers: headers() });
        return response.data;
    },

    /** Datei mit Anmeldung laden und im Browser speichern – nie direkt anzeigen */
    async download(item) {
        const response = await http().get(`_action/nova-upload/${item.id}/download`, {
            headers: headers(),
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = item.fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 10000);
    },

    async remove(item) {
        await http().delete(`_action/nova-upload/${item.id}`, { headers: headers() });
    },

    formatSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
        }
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },
};
