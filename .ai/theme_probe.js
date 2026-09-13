// One-off probe: the Theme Studio form reports only "Capability call failed",
// discarding the cause. The JSON endpoint unwraps it via catThemeJsonError(), so
// drive the same capability over the API to surface the real CatThemeException.
const { chromium } = require('@playwright/test');

const TENANT = 'http://akiracms.test';

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext();

    const page = await ctx.newPage();
    await page.goto(`${TENANT}/login`);
    await page.fill('#username', 'charlienacario884');
    await page.fill('#password', 'iKabud6123!#');
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/cms-akira-shell(?:\/|$)/, { timeout: 20000 });

    await page.goto(`${TENANT}/cms-akira-theme`);
    const token = await page.locator('input[name="_token"]').first().inputValue();
    console.log('csrf token acquired:', token ? 'yes' : 'no');

    const res = await ctx.request.post(
        `${TENANT}/api/v1/cms-akira-theme/themes/akira-editorial/activate`,
        {
            headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
            data: { idempotency_key: `probe-${Date.now()}` },
        },
    );

    console.log('HTTP status:', res.status());
    console.log('body:', await res.text());

    await browser.close();
})();
