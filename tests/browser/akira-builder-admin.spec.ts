// CMS Akira Builder admin — PW-2 browser journey (documented, runnable against a
// full Ikabud deployment with the cms-akira-profile-visual graph installed and the
// builder module activated per tenant via the module-install path).
//
// Journey: shell login → compositions list → open a post's composition → add a real
// theme block → save draft → render preview → validate → publish → published state
// visible on the list.
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
// Run (from repo root, after `npm ci` with @playwright/test + a chromium browser):
//   TENANT_URL=http://akiracms.test npx playwright test tests/browser/akira-builder-admin.spec.ts
//
// Evidence: the console/playwright trace captures the create/save/preview/validate/
// publish steps against the authenticated capability bridge. The browser journey is
// the human-verifiable confirmation that the server authority's HTTP bridge and the
// CSP-safe admin bundle work end to end.
//
// ── Why this spec was rewritten (2026-09-14) ────────────────────────────────
// The original spec was authored in commit a6c76f5 (Phase 10B) against an imagined
// UI. Four of its assertions were impossible from authorship, and it never executed
// past line 79:
//   1. It asserted "Composition editor" on the list route. app.tsx:52 selects the
//      panel from boot.mode; list mode renders <h2>Compositions</h2> (app.tsx:80) and
//      "Composition editor" exists only in EditPanel (app.tsx:435). The string cannot
//      appear on that route.
//   2. Its only relative page.goto() resolved against baseURL, which .env points at
//      the kernel host, so it 404'd and the builder root was absent. The baseURL
//      precedence is fixed in playwright.config.js (TENANT_URL now wins); the
//      relative-navigation proof is asserted in this spec.
//   3. It clicked button:has-text("+ heading"), and no heading block exists. The active
//      theme exposes hero, richtext, card-grid, quote and cta, and the control renders
//      "+ Add {label}" (app.tsx:497). This spec adds a real richtext block; the choice
//      is documented at the point of use.
//   4. It attached the composition to a random key that named no post, then tried to
//      publish. akira.builder.publish@1 resolves the attached post through
//      akira.post.get@1, which only returns published posts
//      (cms-akira-core/helpers/capabilities.php:200), so publish could never succeed.
//      This spec creates a deterministic published post fixture and cleans it up.
//
// Almost every assertion below still checks the same user-observable outcome as the
// original. The change report in the run log records each substitution with its
// file:line evidence.

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? process.env.APP_URL ?? 'http://akiracms.test';

/**
 * The builder's publish capability resolves the attached post through
 * `akira.post.get@1`, which only returns posts whose status is `published`
 * (modules/cms-akira/cms-akira-core/helpers/capabilities.php:200). A composition
 * attached to a missing or still-draft post can be created, saved and previewed,
 * but `akira.builder.publish@1` answers 422 ("A published-capable Akira Post
 * reference is required", helpers.php:903). The original spec attached its
 * composition to a timestamp key that named no post, so its publish step was
 * unreachable. This fixture creates the published post the journey attaches to.
 */
async function createPublishedPost(page: Page, title: string, slug: string): Promise<void> {
  await page.goto(`${TENANT}/cms-akira-shell/posts/new`);
  await page.locator('input[name="title"]').fill(title);
  await page.locator('input[name="slug"]').fill(slug);
  await page.locator('textarea[name="content"]').fill(`Builder journey fixture for ${slug}.`);
  await page.getByRole('button', { name: 'Save post' }).click();
  await page.waitForURL(/\/cms-akira-shell\/posts(?:\?|$)/, { timeout: 20000 });

  // Publish through the governed workflow so akira.post.get@1 can resolve it.
  await page.goto(`${TENANT}/cms-akira-shell/posts/${slug}/edit`);
  for (const action of ['Submit', 'Approve', 'Publish']) {
    const button = page.locator(`button[type="submit"][name="action"][value="${action.toLowerCase()}"]`);
    await expect(button, `workflow action ${action} must be offered`).toBeVisible({ timeout: 15000 });
    await button.click();
    await page.waitForURL(/\/cms-akira-shell\/posts\/.+\/edit/, { timeout: 20000 });
    console.log(`[builder] fixture post workflow ${action} -> ok`);
  }
  await expect(page.locator('[data-akira-workflow-state]')).toContainText(/published/i, { timeout: 15000 });
  console.log(`[builder] fixture post published -> ${slug}`);
}

/**
 * Remove the fixture post and its composition so the journey is deterministic and
 * leaves no residue. Best effort: cleanup must never mask the journey's result.
 */
async function cleanupFixture(page: Page, slug: string): Promise<void> {
  // 1. Delete the composition through the builder's own capability bridge.
  try {
    await page.request.post(`${TENANT}/api/v1/cms-akira/builder/compositions/${slug}/delete`, {
      data: { entity_type: 'post', entity_key: slug, idempotency_key: `builder-cleanup-${Date.now().toString(36)}` },
    });
  } catch {
    // The composition may never have been created (early failure); nothing to remove.
  }

  // 2. Delete the post through the shell's delete route. It enforces CSRF, so the
  //    token is read from the authenticated edit page.
  try {
    const edit = await page.request.get(`${TENANT}/cms-akira-shell/posts/${slug}/edit`);
    if (edit.ok()) {
      const html = await edit.text();
      const token = html.match(/name="_token" value="([^"]+)"/)?.[1];
      // akira.post.delete@1 is optimistic: it requires the row's current
      // expected_updated_at or answers 409 (capabilities.php:672).
      const expected = html.match(/name="expected_updated_at" value="([^"]*)"/)?.[1] ?? '';
      if (token) {
        await page.request.post(`${TENANT}/cms-akira-shell/posts/${slug}/delete`, { form: { _token: token, expected_updated_at: expected } });
      }
    }
  } catch {
    // A failed cleanup is reported by the next run's unique slug, not by this test.
  }
  console.log(`[builder] fixture cleaned -> ${slug}`);
}

test('builder admin: create -> save draft -> preview -> validate -> publish', async ({ page }) => {
  test.setTimeout(180_000);
  const stamp = Date.now().toString(36);
  const slug = `pw-builder-${stamp}`;
  const postTitle = `PW builder post ${stamp}`;
  const compositionTitle = `PW composition ${stamp}`;
  const heading = `PW heading ${stamp}`;
  const body = `PW body ${stamp}`;

  try {
    // Fixture: the journey needs a published-capable post to attach to. This is
    // setup for the builder journey, not a step of it.
    await createPublishedPost(page, postTitle, slug);

    // 1. Open the builder list. This is deliberately a RELATIVE navigation: it
    //    proves the baseURL precedence fix in playwright.config.js sends relative
    //    navigation to the tenant. Before the fix TENANT_URL was shadowed by
    //    APP_URL (the kernel host), so this request 404'd.
    const listResponse = await page.goto('/cms-akira-shell/compositions');
    expect(listResponse?.status(), 'relative navigation must be served by the tenant').toBe(200);
    expect(new URL(page.url()).origin, 'relative navigation must land on the tenant host').toBe(new URL(TENANT).origin);
    console.log(`[builder] relative goto -> ${page.url()} (${listResponse?.status()})`);

    await expect(page.locator('#cms-akira-builder-root')).toBeVisible({ timeout: 15000 });
    // List mode renders ListPanel. These are the strings that route actually ships:
    // <h2>Compositions</h2> and the <h3>Attach a composition to a post</h3>
    // (admin-ui/src/app.tsx:80,84). "Composition editor" is asserted on the editor
    // route below, where EditPanel (app.tsx:435) actually renders it.
    const builder = page.locator('#cms-akira-builder-root');
    await expect(builder.getByRole('heading', { name: 'Compositions', exact: true })).toBeVisible();
    await expect(builder.getByRole('heading', { name: 'Attach a composition to a post' })).toBeVisible();

    // 2. Open the composition for the fixture post through the real list control.
    const composeLink = page.getByRole('link', { name: `Compose “${postTitle}”` });
    await expect(composeLink, 'the published fixture post must be offered for composition').toBeVisible({ timeout: 15000 });
    await composeLink.click();
    await page.waitForURL(new RegExp(`/cms-akira-shell/compositions/${slug}/edit$`), { timeout: 20000 });
    await expect(page.getByRole('heading', { name: 'Composition editor' })).toBeVisible({ timeout: 15000 });
    console.log('[builder] editor opened -> Composition editor');

    // 3. Title + a real theme block. The active theme (akira-editorial) exposes
    //    hero, richtext, card-grid, quote and cta — there is no "heading" block.
    //    richtext is the shipped heading primitive: its title prop renders as an
    //    <h2> and it also carries body copy, so the preview can prove content.
    //    hero was rejected because it is a page-level layout section, not a
    //    heading section.
    await expect(page.locator('.ab-block-form')).toBeVisible({ timeout: 15000 });
    await page.getByLabel('Title').first().fill(compositionTitle);
    await page.locator('.ab-block-form').getByLabel('Block').selectOption('richtext');
    await page.locator('.ab-block-form').getByLabel('Title').fill(heading);
    await page.locator('.ab-block-form').getByLabel('Body').fill(body);
    await page.getByRole('button', { name: '+ Add Rich text' }).click();
    await expect(page.getByLabel('Composition block canvas')).toContainText('Rich text');
    await expect(page.getByLabel('Composition block canvas')).toContainText(heading);
    console.log('[builder] block added -> richtext');

    // 4. Save the draft. When there is no current revision the control is
    //    "Create & save draft"; once a revision exists it becomes "Save draft".
    //    The original asserted the transient .ab-ok message, but handleSave clears
    //    it in the reload() that follows (app.tsx handleSave), so the durable proof
    //    is the control flip plus the server-rendered preview of the saved draft.
    await page.getByRole('button', { name: 'Create & save draft' }).click();
    await expect(page.getByRole('button', { name: 'Save draft' }), 'a saved revision must exist').toBeVisible({ timeout: 20000 });

    // 5. Preview the saved draft. handleSave auto-renders it; the iframe body is
    //    the server-rendered composition and must contain the authored heading.
    const previewFrame = page.frameLocator('iframe[title="composition-preview"]');
    await expect(page.locator('iframe[title="composition-preview"]')).toBeVisible({ timeout: 20000 });
    await expect(previewFrame.getByRole('heading', { name: heading }), 'the saved draft must render server-side').toBeVisible({ timeout: 15000 });
    await expect(previewFrame.getByText(body)).toBeVisible({ timeout: 15000 });
    console.log('[builder] preview rendered saved draft');

    // 6. Validate the draft tree through the governed validate capability.
    await page.getByRole('button', { name: 'Validate', exact: true }).click();
    await expect(page.locator('.ab-ok')).toContainText('Validated', { timeout: 15000 });
    console.log('[builder] validate -> ok');

    // 7. Publish (confirm dialog) and assert the published state. The original
    //    asserted .ab-pill-pub; it is kept, now that publish can actually succeed.
    page.once('dialog', (dialog) => void dialog.accept());
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.locator('.ab-pill-pub').first()).toContainText('PUBLISHED', { timeout: 20000 });
    await expect(page.locator('body')).toContainText(new RegExp(`published revision \\d+`), { timeout: 15000 });
    console.log('[builder] publish -> published');

    // 8. The published state must be visible on the list route too, which proves it
    //    persisted in storage and not merely in the editor's React state.
    await page.goto(`${TENANT}/cms-akira-shell/compositions`);
    // Two links share this href: the "Compose" control for the post and the row in
    // "Existing compositions". Only the latter carries the composition title.
    const publishedRow = page.getByRole('link').filter({ hasText: compositionTitle });
    await expect(publishedRow).toContainText(compositionTitle, { timeout: 15000 });
    await expect(publishedRow.locator('.ab-pill-pub')).toHaveText('PUBLISHED');
    console.log('[builder] list shows published composition');
  } finally {
    await cleanupFixture(page, slug);
  }
});
