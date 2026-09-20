'use strict';

/** Keep every colour control and its readable value in sync while editing. */
const colourInputs = document.querySelectorAll('[data-theme-color-input]');
const preview = document.querySelector('[data-theme-preview]');
const editStatus = document.querySelector('[data-theme-edit-status]');
colourInputs.forEach((input) => {
    const value = input.parentElement?.querySelector('[data-theme-color-value]');
    const field = input.name.match(/\[([^\]]+)\]$/)?.[1];
    const token = field ? `--${field.replaceAll('_', '-')}` : null;
    const render = () => {
        input.style.backgroundColor = input.value;
        if (value) {
            value.textContent = input.value.toUpperCase();
        }
        if (token) {
            // Expose the resolved token on the document and update the preview's
            // local override so the operator sees the edit without submitting.
            document.documentElement.style.setProperty(token, input.value);
            preview?.style.setProperty(token, input.value);
        }
    };

    input.addEventListener('input', () => {
        render();
        if (editStatus) {
            editStatus.textContent = 'Unsaved changes';
        }
    });
    input.addEventListener('change', render);
    render();
});

/** Restore the values loaded from the server without submitting or changing the saved theme. */
document.querySelector('[data-theme-discard]')?.addEventListener('click', (event) => {
    const form = event.currentTarget.closest('form');
    if (!form) {
        return;
    }

    form.reset();
    colourInputs.forEach((input) => {
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    if (editStatus) {
        editStatus.textContent = 'Changes discarded';
    }
});
