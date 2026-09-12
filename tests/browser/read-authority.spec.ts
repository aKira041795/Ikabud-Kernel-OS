// Read authority — a declared READ route must still work for an authorised actor.
//
// The probe test proves dispatch refuses a declared read with no authority. The
// risk of that change is the opposite one: refusing a legitimate operator. This
// spec exists because a DiSyL defect once broke kernel login while every HTTP
// status check passed (PR #125) — "the API is fine" is not evidence that a page
// works, and neither is "the test suite is green".
//
// Tenant admin post list is the route that now carries a declaration:
//   GET /cms-akira-shell/posts => akira.post.admin.list@1
//
// Run:
//   TENANT_URL=http://akiracms.test TENANT_USER=... TENANT_PASS=... \
//   npx playwright test -c /tmp/pw-live.config.js tests/browser/read-authority.spec.ts

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';
const TENANT_USER = process.env.TENANT_USER ?? 'charlienacario884';
const TENANT_PASS = process.env.TENANT_PASS ?? 'iKabud6123!#';

function watch(page: Page) {
    const consoleErrors: string[] = [];
    const denied: string[] = [];
    page.on('console', (m) => {
        if (m.type() === 'error') consoleErrors.push(m.text());
    });
    page.on('response', (r) => {
        if (r.status() === 403) denied.push(`${r.status()} ${r.url()}`);
    });
    return { consoleErrors, denied };
}

test('admin post list renders for an authorised operator (read declaration must not break it)', async ({ page }) => {
    const w = watch(page);

    await page.goto(`${TENANT}/login`);
    await page.fill('#username', TENANT_USER);
    await page.fill('#password', TENANT_PASS);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/cms-akira-shell/, { timeout: 20000 });

    const response = await page.goto(`${TENANT}/cms-akira-shell/posts`);

    console.log(`[read] status: ${response?.status()}`);
    console.log(`[read] landed on: ${page.url()}`);
    console.log(`[read] 403 responses: ${JSON.stringify(w.denied)}`);
    console.log(`[read] console errors: ${JSON.stringify(w.consoleErrors.slice(0, 5))}`);

    // The whole point: an authorised operator must not be refused by the new
    // read declaration.
    expect(response?.status(), 'the declared read must not 403 for an authorised operator').not.toBe(403);

    // And it must actually be the admin surface, not an error page.
    await expect(page.locator('body')).toContainText(/post/i);
    expect(page.url()).toContain('/cms-akira-shell/posts');
});
