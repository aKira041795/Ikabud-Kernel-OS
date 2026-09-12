// Browser verification of both live hosts — kernel (control plane) and tenant.
//
// The point of driving a real browser rather than curl is that server-rendered
// JavaScript is executed here. Static HTTP checks confirmed the login *API*
// works (POST /api/v1/auth/login returns 200), but they cannot see whether the
// page's own script builds a usable request — which is where this spec found a
// defect.
//
// Run:
//   KERNEL_URL=http://ikabudsix.test TENANT_URL=http://akiracms.test \
//   KERNEL_USER=akiraadmin KERNEL_PASS=... TENANT_USER=... TENANT_PASS=... \
//   npx playwright test tests/browser/live-hosts.spec.ts

import { test, expect, type Page } from '@playwright/test';

const KERNEL = process.env.KERNEL_URL ?? 'http://ikabudsix.test';
const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';

const KERNEL_USER = process.env.KERNEL_USER ?? 'akiraadmin';
const KERNEL_PASS = process.env.KERNEL_PASS ?? 'iKabud6123!#';
const TENANT_USER = process.env.TENANT_USER ?? 'charlienacario884';
const TENANT_PASS = process.env.TENANT_PASS ?? 'iKabud6123!#';

/** Collect console errors and failed requests — invisible to curl. */
function watch(page: Page) {
    const consoleErrors: string[] = [];
    const failed: string[] = [];
    const posts: string[] = [];
    page.on('console', (m) => {
        if (m.type() === 'error') consoleErrors.push(m.text());
    });
    page.on('requestfailed', (r) => failed.push(`${r.method()} ${r.url()} — ${r.failure()?.errorText}`));
    page.on('request', (r) => {
        if (r.method() === 'POST') posts.push(r.url());
    });
    return { consoleErrors, failed, posts };
}

test('kernel host: login form reaches the admin surface', async ({ page }) => {
    const w = watch(page);
    await page.goto(`${KERNEL}/login`);

    // The template's own script must build a usable endpoint. If it does not,
    // this is a server-side template defect, not a credentials problem.
    const endpointLiteral = await page
        .locator('script')
        .allInnerTexts()
        .then((blocks) => blocks.join('\n').match(/loginEndpoint\s*=\s*'([^']*)'/)?.[1] ?? null);
    console.log(`[kernel] loginEndpoint literal rendered as: ${JSON.stringify(endpointLiteral)}`);

    await page.fill('input[name="username"]', KERNEL_USER);
    await page.fill('input[name="password"]', KERNEL_PASS);
    await page.click('#login-btn');
    await page.waitForTimeout(2500);

    console.log(`[kernel] POST targets: ${JSON.stringify(w.posts)}`);
    console.log(`[kernel] failed requests: ${JSON.stringify(w.failed.slice(0, 5))}`);
    console.log(`[kernel] console errors: ${JSON.stringify(w.consoleErrors.slice(0, 5))}`);
    console.log(`[kernel] landed on: ${page.url()}`);

    // A malformed endpoint literal is the root cause; assert it is well-formed.
    expect(endpointLiteral, 'loginEndpoint must not contain unrendered template markers').not.toMatch(/\{\/?if\b/);
    await expect(page, 'kernel login must land on the admin surface').toHaveURL(/\/admin/, { timeout: 10000 });
});

test('tenant host: login and admin shell render', async ({ page }) => {
    const w = watch(page);
    await page.goto(`${TENANT}/login`);

    // The Akira sign-in form binds with Alpine (x-model) and ids, not name attrs.
    await page.fill('#username', TENANT_USER);
    await page.fill('#password', TENANT_PASS);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/cms-akira-shell/, { timeout: 20000 });

    console.log(`[tenant] landed on: ${page.url()}`);
    console.log(`[tenant] POST targets: ${JSON.stringify(w.posts)}`);
    console.log(`[tenant] failed requests: ${JSON.stringify(w.failed.slice(0, 5))}`);
    console.log(`[tenant] console errors: ${JSON.stringify(w.consoleErrors.slice(0, 5))}`);

    await expect(page.locator('body')).toContainText(/Dashboard|Posts/i, { timeout: 10000 });
});
