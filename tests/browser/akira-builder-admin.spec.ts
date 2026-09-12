// CMS Akira Builder admin — PW-2 browser journey (documented, runnable against a
// full Ikabud deployment with the cms-akira-profile-visual graph installed and the
// builder module activated per tenant via the module-install path).
//
// Journey: shell login → compositions → create composition → save draft →
// render preview → publish.
//
// Provisioning recipe (verified 2026-09-08 against a local single-tenant runtime):
//   1. Ensure the Akira modules are loadable (php ikabud module:list shows the
//      cms-akira-* set enabled in the global registry — single-tenant mode).
//   2. Create a kernel tenant whose entry is the Akira shell and map your host:
//        php ikabud tenant:create cmsakira <your-domain> --entry=cms-akira-shell
//        (or insert kernel_tenants + kernel_tenant_domains rows for 127.0.0.1).
//   3. Install the full visual graph through the Kernel module-install service:
//        php ikabud tenant:module:install <tenant> cms-akira-profile-visual --set-entry
//      (writes the committed install generation; entry cms-akira-shell becomes routable).
//   4. Seed a published post + an admin user (users table, role 'admin'/'superadmin').
//   5. Serve public/router.php (php -S) under the mapped host.
//
// Sandbox blocker (recorded): in a kernel-only installer repo (no DNS / canonical
// host / working kernel login), the kernel /login middleware self-redirects
// (303 /login -> /login; browser ERR_TOO_MANY_REDIRECTS) BEFORE any Akira page can
// authenticate, independent of CMS Akira. This spec therefore requires a real
// deployment whose kernel /login renders. It is deliberately kept as a runnable
// spec rather than an executed gate for that reason.
//
// Run (from repo root, after `npm ci` with @playwright/test + a chromium browser):
//   APP_URL=https://<tenant-host> AKIRA_USER=<admin> AKIRA_PASS=<pass> npx playwright test tests/browser/akira-builder-admin.spec.ts
//
// Evidence: the console/playwright trace captures the create/save/preview/publish
// steps against the authenticated capability bridge. The browser journey is the
// human-verifiable confirmation that the server authority's HTTP bridge and the
// CSP-safe admin bundle work end to end; it requires a live tenant because the
// builder module is tracked `_enabled:false` and only becomes routable after an
// explicit per-tenant install (proven by the module-install path tests, not by
// this browser spec).

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? process.env.APP_URL ?? 'http://akiracms.test';
// Same env convention as the other Akira specs (akira-media-admin, live-hosts) and
// the same defaults they document, so one command runs every Akira journey.
const TENANT_USER = process.env.TENANT_USER ?? 'charlienacario884';
const TENANT_PASS = process.env.TENANT_PASS ?? 'iKabud6123!#';

const API_BASE = '/api/v1/cms-akira/builder';

async function ensureAuthed(page: Page): Promise<void> {
  // Log in proactively. The previous version navigated first and only logged in if
  // the URL looked like a /login redirect -- but the shell's protected routes are
  // refused by the fail-closed route authority guard with a 403, they do not
  // redirect. So the branch never ran, and the fallback credentials
  // (admin@example.test / change-me) could never authenticate: the spec was
  // unrunnable against any real tenant.
  await page.goto(`${TENANT}/login`);
  await page.fill('#username', TENANT_USER);
  await page.fill('#password', TENANT_PASS);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForURL(/\/cms-akira-shell(?:\/|$)/, { timeout: 20000 });

  await page.goto('/cms-akira-shell/compositions');
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
