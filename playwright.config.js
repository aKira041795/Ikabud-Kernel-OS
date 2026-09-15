// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const path = require('node:path');

// Load the git-ignored .env so the live-host specs get credentials without any of
// them being embedded in tracked files. The config is evaluated before test workers
// are spawned, so values set on process.env here reach the specs.
//
// Parsed with the standard library on purpose: a .env loader is ~15 lines and does
// not justify adding dotenv as a dependency.
//
// Existing environment variables always win, so `TENANT_PASS=... npx playwright test`
// still overrides the file.
(function loadDotEnv() {
    const fs = require('node:fs');
    const path = require('node:path');
    const file = path.join(__dirname, '.env');
    if (!fs.existsSync(file)) return;
    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
        const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
        if (!match) continue;
        let value = match[2].trim();
        if (value.length > 1 && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))) {
            value = value.slice(1, -1);
        }
        if (process.env[match[1]] === undefined) process.env[match[1]] = value;
    }
})();

// One login for the whole suite. The kernel login limiter permits 5 attempts per
// 300s, and every authenticated spec used to submit the form itself, which tripped
// the limiter and made the later specs look like an auth regression. Global setup
// logs in once and writes a storageState the suite project loads; the specs then
// make zero login POSTs. (A `setup` *project* would add its own test to `--list` and
// the pass count, breaking the required 9-test contract; global setup does not.)
const TENANT_STORAGE_STATE = path.join(__dirname, 'tests', 'browser', '.auth', 'tenant.json');
process.env.PW_TENANT_STORAGE_STATE = TENANT_STORAGE_STATE;

module.exports = defineConfig({
    testDir: './tests/browser',
    // Runs before any project. Logs in once and saves the tenant storageState above.
    globalSetup: './tests/browser/auth.setup.ts',
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
        // TENANT_URL wins; APP_URL is the kernel/control host in .env. The default
        // is the tenant host these specs actually drive -- the previous default
        // (palsystem.test) is stale, and a relative page.goto() must resolve to the
        // tenant (the builder's /cms-akira-shell/* routes 404 on the kernel host),
        // not to whichever host APP_URL happens to name.
        baseURL: process.env.TENANT_URL || process.env.APP_URL || 'http://akiracms.test',
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
            use: {
                ...devices['Desktop Chrome'],
                // The authenticated session written by auth.setup.ts. Specs that test
                // anonymous behaviour (and live-hosts, which drives the form itself)
                // override this with an empty state and opt out.
                storageState: TENANT_STORAGE_STATE,
            },
        },
    ],
});
