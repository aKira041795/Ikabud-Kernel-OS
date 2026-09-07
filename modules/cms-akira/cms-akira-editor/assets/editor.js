(() => {
    'use strict';

    const selector = '[data-akira-editor]';
    const initialize = (root = document) => {
        root.querySelectorAll(selector).forEach((element) => {
            if (element.dataset.akiraEditorReady === 'true') {
                return;
            }
            element.dataset.akiraEditorReady = 'true';
            element.dispatchEvent(new CustomEvent('akira:editor:ready', { bubbles: true }));
        });
    };

    window.AkiraEditor = Object.freeze({ initialize });
    document.addEventListener('DOMContentLoaded', () => initialize());
})();
