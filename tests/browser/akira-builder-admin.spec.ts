// CMS Akira Builder admin — PW-2 browser journey (documented, runnable against a
// full Ikabud deployment with the cms-akira-profile-visual graph installed and the
// builder module activated per tenant via the module-install path).
//
// Journey: shell login → compositions → create composition → save draft →
// render preview → publish.
//
// Run (from repo root, after `npm ci` with @playwright/test + a chromium browser):
//   APP_URL=https://<tenant-host> npx playwright test tests/browser/akira-builder-admin.spec.ts
//
// Evidence: the console/playwright trace captures the create/save/preview/publish
// steps against the authenticated capability bridge. The browser journey is the
// human-verifiable confirmation that the server authority's HTTP bridge and the
// CSP-safe admin bundle work end to end; it requires a live tenant because the
// builder module is tracked `_enabled:false` and only becomes routable after an
// explicit per-tenant install (proven by the module-install path tests, not by
// this browser spec).

import { test, expect, type Page } from '@playwright/test';

const API_BASE = '/api/v1/cms-akira/builder';

async function ensureAuthed(page: Page): Promise<void> {
  // Kernel-auth shell: the login page redirects to the Kernel /login form. Drive
  // it if present; otherwise assume a session cookie already exists (injected
  // via the test harness/storage state).
  await page.goto('/cms-akira-shell/compositions');
  const url = page.url();
  if (/\/login(\?|$)/.test(url)) {
    await page.fill('input[name="email"], input[name="username"], input[name="login"]', process.env.AKIRA_USER ?? 'admin@example.test');
    await page.fill('input[name="password"]', process.env.AKIRA_PASS ?? 'change-me');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"], input[type="submit"]')]);
    await page.goto('/cms-akira-shell/compositions');
  }
  await expect(page.locator('#cms-akira-builder-root')).toBeVisible({ timeout: 15000 });
}

test('builder admin: create -> save draft -> preview -> publish', async ({ page }) => {
  test.setTimeout(120_000);
  const key = `pw-${Date.now().toString(36)}`;
  await ensureAuthed(page);

  // "Compositions" list (list mode)
  await expect(page.locator('text=Compositions').first()).toBeVisible();
  await expect(page.locator('#cms-akira-builder-root')).toContainText('Composition editor');

  // Ensure a published-capable post exists to attach to, then open the editor.
  await page.goto(`/cms-akira-shell/compositions/${key}/edit`);
  await expect(page.locator('#cms-akira-builder-root')).toContainText('Composition editor');

  // Title + a validated block (heading), then create/save draft.
  await page.locator('#cms-akira-builder-root input').first().fill(`PW composition ${key}`);
  await page.locator('button:has-text("+ heading")').click();

  // Save draft (create when no current revision, else append base revision).
  await page.locator('button:has-text("Create & save draft"), button:has-text("Save draft")').first().click();
  await expect(page.locator('.ab-ok, .ab-error').first()).toContainText(/Created|Saved/);

  // Render preview (server-rendered draft) into the sandboxed iframe.
  await page.locator('button:has-text("Render preview (draft)")').click();
  await expect(page.locator('iframe[title="composition-preview"]')).toBeVisible();

  // Validate.
  await page.locator('button:has-text("Validate")').click();

  // Publish with confirm.
  page.once('dialog', (dialog) => void dialog.accept());
  await page.locator('button:has-text("Publish")').click();
  await expect(page.locator('.ab-pill-pub')).toContainText('PUBLISHED');
});
