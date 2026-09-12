// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30000,
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: [
        ['list'],
        // A second entry previously pointed at ./tests/browser/WorkbenchReporter.js,
        // which does not exist in this tree. Playwright refuses to load a config whose
        // reporter cannot be resolved, so `npm test` could not run at all -- which is
        // why ad-hoc temporary configs kept appearing. Removed rather than stubbed:
        // nothing else references it, and inventing a reporter nothing consumes would
        // be a bandaid. Add it back together with its consumer.
    ],
    use: {
        // APP_URL wins; TENANT_URL is the name the specs themselves use. The default
        // is the tenant host these specs actually drive -- the previous default
        // (palsystem.test) is stale, and because the Akira specs use relative
        // page.goto() it made them impossible to run under this config.
        baseURL: process.env.APP_URL || process.env.TENANT_URL || 'http://akiracms.test',
        // Headless by default so CI never tries to open a window; opt in to a
        // visible browser with PW_HEADED=1 (or `npx playwright test --headed`).
        // Without this there was no way to watch a journey run, which is why the
        // only evidence anyone saw was terminal output.
        headless: process.env.PW_HEADED !== '1',
        viewport: { width: 1280, height: 720 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
