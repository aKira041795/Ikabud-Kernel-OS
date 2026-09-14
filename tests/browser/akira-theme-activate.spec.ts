// Theme activation journey — studio → runtime → public render.
//
// This spec exists because those three layers could disagree without anything failing.
// `php ikabud theme:activate` reported success while writing a legacy `cms.active_theme`
// setting that the runtime never reads, so `theme:current` said "Akira Editorial" while
// every public page still rendered the shell fallback. A theme can be installed, valid,
// ARK-visible, and still never render. The only honest check is the full chain.
//
// Run (visible browser):
//   PW_HEADED=1 npx playwright test tests/browser/akira-theme-activate.spec.ts

import { test, expect } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? process.env.APP_URL ?? 'http://akiracms.test';
const THEME = process.env.AKIRA_THEME ?? 'akira-editorial';

test('theme studio: activating a theme must reach the runtime and the public page', async ({ page }) => {
    test.setTimeout(120_000);
    // The authenticated session comes from auth.setup.ts's storageState; this spec
    // makes no login POST of its own.

    // 0. What the runtime currently resolves, before we touch anything.
    const before = await page.request.get(`${TENANT}/api/v1/cms-akira-theme/resolve`);
    expect(before.status(), 'resolve endpoint must answer').toBe(200);
    const beforeJson = await before.json();
    console.log(`[theme] resolve before -> ${JSON.stringify(beforeJson)}`);

    // 1. Activate through the Theme Studio UI, which posts to the governed
    //    `akira.theme.activate@1` capability (policy + audit + idempotency).
    await page.goto(`${TENANT}/cms-akira-theme`);
    const item = page.locator('li').filter({ hasText: THEME }).first();
    await expect(item, `theme ${THEME} must be listed as installed`).toBeVisible({ timeout: 15000 });
    await item.getByRole('button', { name: 'Activate' }).click();

    // 2. The studio must agree. The active theme is marked in the installed list.
    await expect(
        page.locator('li').filter({ hasText: THEME }).first(),
        'the studio must mark the activated theme as active',
    ).toContainText('(active)', { timeout: 15000 });

    // 3. The runtime must agree with the studio — this is the seam that silently broke.
    const after = await page.request.get(`${TENANT}/api/v1/cms-akira-theme/resolve`);
    const afterJson = await after.json();
    console.log(`[theme] resolve after  -> ${JSON.stringify(afterJson)}`);
    expect(afterJson.ok, 'runtime resolution must succeed').toBe(true);
    expect(afterJson.theme_slug, 'runtime must resolve the activated theme').toBe(THEME);

    // 4. And the public page must actually be rendered by that theme's ARK renderers,
    //    not by the shell fallback. Zero renderers is the failure this spec exists for.
    const home = await page.goto(`${TENANT}/?cb=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    expect(home?.status()).toBe(200);
    const renderers = await page.locator('[data-ark-renderer]').count();
    console.log(`[theme] public data-ark-renderer nodes -> ${renderers}`);
    expect(
        renderers,
        'public pages must be rendered by ARK (data-ark-renderer), not the shell fallback',
    ).toBeGreaterThan(0);
});
