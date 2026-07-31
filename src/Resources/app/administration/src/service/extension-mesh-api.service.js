const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class ExtensionMeshApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'extension-mesh');
        this.productIntegrationRequests = new Map();
    }

    getExtensions(locale) {
        return this.httpClient
            .get('_action/extension-mesh/extensions', {
                params: { locale },
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data.data);
    }

    addRegistry(url, accessToken = null) {
        return this.httpClient
            .post(
                '_action/extension-mesh/registries',
                { url, accessToken: accessToken || null },
                { headers: this.getBasicHeaders() },
            )
            .then((response) => response.data);
    }

    updateRegistryCredential(id, accessToken = null) {
        return this.httpClient.put(
            `_action/extension-mesh/registries/${id}/credential`,
            { accessToken: accessToken || null },
            { headers: this.getBasicHeaders() },
        );
    }

    removeRegistry(id) {
        return this.httpClient.delete(`_action/extension-mesh/registries/${id}`, {
            headers: this.getBasicHeaders(),
        });
    }

    refreshRegistries() {
        return this.httpClient.post(
            '_action/extension-mesh/refresh',
            {},
            { headers: this.getBasicHeaders() },
        );
    }

    getEntitlementOptions() {
        return this.httpClient
            .get('_action/extension-mesh/entitlements/options', {
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data.data);
    }

    synchronizePublications() {
        return this.httpClient.post(
            '_action/extension-mesh/publication/synchronize',
            {},
            { headers: this.getBasicHeaders() },
        );
    }

    getRepositoryProviders() {
        return this.httpClient
            .get('_action/extension-mesh/repositories/providers', {
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data.data);
    }

    connectSellerRepository(payload) {
        return this.httpClient
            .post(
                '_action/extension-mesh/repositories',
                payload,
                { headers: this.getBasicHeaders() },
            )
            .then((response) => response.data.data);
    }

    syncSellerRepository(id) {
        return this.httpClient
            .post(
                `_action/extension-mesh/repositories/${id}/sync`,
                {},
                { headers: this.getBasicHeaders() },
            )
            .then((response) => response.data.data);
    }

    updateSellerRepositoryCredential(id, accessToken) {
        return this.httpClient
            .put(
                `_action/extension-mesh/repositories/${id}/credential`,
                { accessToken },
                { headers: this.getBasicHeaders() },
            )
            .then((response) => response.data.data);
    }

    unlinkSellerRepository(id) {
        return this.httpClient.delete(`_action/extension-mesh/repositories/${id}`, {
            headers: this.getBasicHeaders(),
        });
    }

    getProductIntegration(productId, refresh = false) {
        if (!refresh && this.productIntegrationRequests.has(productId)) {
            return this.productIntegrationRequests.get(productId);
        }

        const request = this.httpClient
            .get(`_action/extension-mesh/products/${productId}/integration`, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data.data)
            .catch((error) => {
                this.productIntegrationRequests.delete(productId);
                throw error;
            });
        this.productIntegrationRequests.set(productId, request);

        return request;
    }

    setProductIntegration(productId, enabled) {
        const request = this.httpClient
            .put(
                `_action/extension-mesh/products/${productId}/integration`,
                { enabled },
                { headers: this.getBasicHeaders() },
            )
            .then((response) => response.data.data);
        this.productIntegrationRequests.set(productId, request);

        return request;
    }

    getProductDownloads(productId, page = 1, limit = 10) {
        return this.httpClient
            .get(`_action/extension-mesh/products/${productId}/downloads`, {
                params: { page, limit },
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data);
    }

    refreshProductPublication(productId) {
        return this.httpClient.post(
            `_action/extension-mesh/products/${productId}/publication`,
            {},
            { headers: this.getBasicHeaders() },
        );
    }

    prepareExtension(registryId, technicalName) {
        return this.httpClient.post(
            `_action/extension-mesh/download/${registryId}/${technicalName}`,
            {},
            { headers: this.getBasicHeaders() },
        );
    }
}

Application.addServiceProvider('extensionMeshApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new ExtensionMeshApiService(initContainer.httpClient, container.loginService);
});
