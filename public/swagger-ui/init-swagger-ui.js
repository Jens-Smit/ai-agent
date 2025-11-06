// This file is part of the API Platform project.
//
// (c) Kévin Dunglas <dunglas@gmail.com>
//
// For the full copyright and license information, please view the LICENSE
// file that was distributed with this source code.

function loadSwaggerUI(userOptions = {}) {
    const defaultOptions = {
        url: '/api/doc.json',      // <- direkt die JSON-Spezifikation von Nelmio
        dom_id: '#swagger-ui',
        validatorUrl: null,
        presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
        ],
        plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
        ],
        layout: 'StandaloneLayout'
    };

    // Optionen überschreiben, falls userOptions übergeben werden
    const options = Object.assign({}, defaultOptions, userOptions);
    const ui = SwaggerUIBundle(options);

    const storageKey = 'nelmio_api_auth';

    function getAuthorizationsFromStorage() {
        if (sessionStorage.getItem(storageKey)) {
            try {
                return JSON.parse(sessionStorage.getItem(storageKey));
            } catch (ignored) { }
        }
        return {};
    }

    // vorhandene Auth-Daten aus sessionStorage anwenden
    try {
        const currentAuthorizations = getAuthorizationsFromStorage();
        Object.keys(currentAuthorizations).forEach(k =>
            ui.authActions.authorize({ [k]: currentAuthorizations[k] })
        );
    } catch (ignored) { }

    // authorize hook zum Speichern in sessionStorage
    const currentAuthorize = ui.authActions.authorize;
    ui.authActions.authorize = function (payload) {
        try {
            sessionStorage.setItem(
                storageKey,
                JSON.stringify(Object.assign(getAuthorizationsFromStorage(), payload))
            );
        } catch (ignored) { }
        return currentAuthorize(payload);
    };

    // logout hook zum Löschen aus sessionStorage
    const currentLogout = ui.authActions.logout;
    ui.authActions.logout = function (payload) {
        try {
            let currentAuth = getAuthorizationsFromStorage();
            payload.forEach(k => delete currentAuth[k]);
            sessionStorage.setItem(storageKey, JSON.stringify(currentAuth));
        } catch (ignored) { }
        return currentLogout(payload);
    };

    window.ui = ui;
}
