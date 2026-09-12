// Read authority — declared READ routes must still work for an authorised actor.
//
// The probe test proves dispatch refuses a declared read with no authority. The
// risk of that change is the opposite one: refusing a legitimate operator. This
// spec exists because a DiSyL defect once broke kernel login while every HTTP
// status check passed (PR #125) — "the API is fine" is not evidence that a page
// works, and neither is "the test suite is green".
//
// Declared reads now carried by cms-akira-shell:
//   GET /cms-akira-shell/posts                 => akira.post.admin.list@1
//   GET /cms-akira-shell/posts/{slug}/edit     => akira.post.admin.get@1
//
// The extension's other candidates are deliberately NOT declared: their handler
// capabilities (akira.taxonomy.list@1, akira.content_type.list@1,
// akira.policy.list@1, akira.user.list@1, entity.list.post@1) have no policy
// row until increment R5, and the dispatch guard's authorize() is fail-closed —
// declaring them would 403 the page for every operator, administrators
// included. Those pages are loaded below too, to prove the exclusion left them
// working rather than quietly broken. login and forbidden are excluded for
// lockout/loop safety and are checked here as well.
//
// The authenticated checks share one login on purpose: the kernel login rate
// limiter is per-IP, and a spec that logs in once per page would trip its own
// harness. The anonymous check does not submit the form, so it adds no attempt.
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

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT}/login`);
    await page.fill('#username', TENANT_USER);
    await page.fill('#password', TENANT_PASS);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/cms-akira-shell/, { timeout: 20000 });
}

/** Navigate and report, asserting the page was not refused at dispatch. */
async function expectRendered(page: Page, path: string, expected: RegExp): Promise<number> {
    const response = await page.goto(`${TENANT}${path}`, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    console.log(`[read] ${path} -> ${status} (${page.url()})`);
    expect(status, `declared/undeclared page ${path} must not be refused (403)`).not.toBe(403);
    await expect(page.locator('body')).toContainText(expected);
    return status;
}

/** Create a disposable draft through the governed UI; returned slug is live. */
async function createDisposablePost(page: Page): Promise<string> {
    const slug = `read-authority-${Date.now()}`;
    await page.goto(`${TENANT}/cms-akira-shell/posts/new`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="title"]', 'Read authority disposable');
    await page.fill('input[name="slug"]', slug);
    await page.fill('textarea[name="content"]', 'Disposable fixture for the declared read-edit route.');
    await page.click('button:has-text("Save post")');
    await page.waitForURL(/\/cms-akira-shell\/posts(\?|$)/, { timeout: 20000 });
    return slug;
}

/** Best-effort soft-delete through the governed UI so the fixture does not leak. */
async function deleteDisposablePost(page: Page): Promise<void> {
    try {
        await page.goto(`${TENANT}/cms-akira-shell/posts`, { waitUntil: 'domcontentloaded' });
        page.once('dialog', (dialog) => void dialog.accept());
        await page.click('button:has-text("Delete")');
        await page.waitForURL(/\/cms-akira-shell\/posts(\?|$)/, { timeout: 20000 });
    } catch (error) {
        console.log(`[read] cleanup of disposable post failed: ${String(error)}`);
    }
}

test('admin surfaces render for an authorised operator (declared reads and exclusions)', async ({ page }) => {
    const w = watch(page);
    await login(page);

    // ── Declared reads: must not 403, must actually render the admin surface ──
    const postList = await expectRendered(page, '/cms-akira-shell/posts', /governed kernel entity-view pipeline/i);

    // The tenant currently has no live post, so create a disposable one through
    // the governed create capability to give the declared edit route a real page
    // to render. It is soft-deleted again in the finally block.
    const slug = await createDisposablePost(page);
    let editStatus = 0;
    try {
        editStatus = await expectRendered(page, `/cms-akira-shell/posts/${slug}/edit`, /edit post/i);
        expect(page.url()).toContain(`/cms-akira-shell/posts/${slug}/edit`);
    } finally {
        await deleteDisposablePost(page);
    }

    // ── Excluded (ungoverned) admin reads: must still render exactly as before ─
    await expectRendered(page, '/cms-akira-shell/posts/new', /create post/i);
    await expectRendered(page, '/cms-akira-shell/categories', /categor/i);
    await expectRendered(page, '/cms-akira-shell/content-types', /content type/i);
    await expectRendered(page, '/cms-akira-shell/permissions', /permission/i);
    await expectRendered(page, '/cms-akira-shell/users', /user/i);
    await expectRendered(page, '/cms-akira-shell/compositions', /composition/i);
    await expectRendered(page, '/cms-akira-shell/compositions/smoke-test/edit', /edit composition/i);
    await expectRendered(page, '/cms-akira-shell/health', /module health/i);

    // ── Denial surface: reaches its handler, which itself reports 403 ──────────
    const forbidden = await page.goto(`${TENANT}/cms-akira-shell/forbidden`, { waitUntil: 'domcontentloaded' });
    console.log(`[read] /cms-akira-shell/forbidden -> ${forbidden?.status()}`);
    expect(forbidden?.status()).toBe(403);
    await expect(page.locator('body')).toContainText(/access denied/i);

    console.log(`[read] declared list status: ${postList}`);
    console.log(`[read] declared edit status: ${editStatus}`);
    console.log(`[read] 403 responses: ${JSON.stringify(w.denied)}`);
    console.log(`[read] console errors: ${JSON.stringify(w.consoleErrors.slice(0, 5))}`);
    expect(postList).toBe(200);
    expect(editStatus).toBe(200);
});

test('login entry point is unaffected for a fresh unauthenticated visitor', async ({ page }) => {
    // No login() call — this test starts anonymous and never submits credentials.
    const response = await page.goto(`${TENANT}/cms-akira-shell/login`, { waitUntil: 'domcontentloaded' });

    console.log(`[read] /cms-akira-shell/login -> ${response?.status()} landed on ${page.url()}`);

    // The shell route delegates to the stable kernel entry point.
    expect(response?.status(), 'the login entry point must not 403').not.toBe(403);
    await page.waitForURL(/\/login$/, { timeout: 20000 });
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
});
