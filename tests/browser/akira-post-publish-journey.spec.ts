// CMS Akira editorial capability journey — the end-to-end scope check.
//
// Journey: login → upload an image into the governed media library → create a post
// → attach that image as the featured image → publish through the workflow
// (submit → approve → publish) → confirm the public page renders it.
//
// This spec exists because the scope was previously untested end to end: media had a
// spec and the builder had a spec, but nothing proved that an editorial role could get
// a post live WITH an image. Writing it immediately exposed that the post form had no
// featured-image field at all, so the capability, the schema column and all three
// public templates supported something the UI could never send.
//
// Run (visible browser):
//   PW_HEADED=1 npx playwright test tests/browser/akira-post-publish-journey.spec.ts

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? process.env.APP_URL ?? 'http://akiracms.test';
const TENANT_USER = process.env.TENANT_USER ?? 'charlienacario884';
const TENANT_PASS = process.env.TENANT_PASS ?? 'iKabud6123!#';

// Smallest valid PNG (1x1). Uploaded through the real multipart form.
const PIXEL_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);

async function login(page: Page): Promise<void> {
  await page.goto(`${TENANT}/login`);
  await page.fill('#username', TENANT_USER);
  await page.fill('#password', TENANT_PASS);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForURL(/\/cms-akira-shell(?:\/|$)/, { timeout: 20000 });
}

/** Upload through the media surface and return the stored URL. */
async function uploadImage(page: Page, filename: string): Promise<string> {
  await page.goto(`${TENANT}/cms-akira-shell/media`);
  await expect(page.locator('form[data-akira-media-upload]')).toBeVisible();
  await page.locator('input[name="media_file"]').setInputFiles({
    name: filename,
    mimeType: 'image/png',
    buffer: PIXEL_PNG,
  });
  await page.locator('form[data-akira-media-upload] button[type="submit"]').click();
  await page.waitForURL(/\/cms-akira-shell\/media(\?|$)/, { timeout: 20000 });

  const row = page.locator(`[data-akira-media-row]:has-text("${filename}")`).first();
  await expect(row).toBeVisible({ timeout: 15000 });
  const src = await row.locator('img').first().getAttribute('src');
  expect(src, 'uploaded media must expose a renderable url').toBeTruthy();
  return src as string;
}

test('editorial journey: create a post with a featured image and publish it', async ({ page }) => {
  test.setTimeout(180_000);
  const stamp = Date.now().toString(36);
  const slug = `pw-post-${stamp}`;

  await login(page);

  // 1. Image first: the post can only select media that exists in the library.
  const imageUrl = await uploadImage(page, `pw-post-image-${stamp}.png`);
  console.log(`[journey] uploaded media -> ${imageUrl}`);

  // 2. Create the post and attach the featured image.
  await page.goto(`${TENANT}/cms-akira-shell/posts/new`);
  await page.fill('input[name="title"]', `PW journey post ${stamp}`);
  await page.fill('input[name="slug"]', slug);
  await page.fill('textarea[name="content"]', `Journey content for ${stamp}.`);

  const imageSelect = page.locator('select[name="image"]');
  await expect(imageSelect, 'the editor must offer a featured image').toBeVisible({ timeout: 15000 });
  await imageSelect.selectOption(imageUrl);

  await page.locator('button:has-text("Save post")').click();
  await page.waitForURL(/\/cms-akira-shell\/posts(\?|$)/, { timeout: 20000 });
  await page.goto(`${TENANT}/cms-akira-shell/posts/${slug}/edit`);
  await expect(page.locator('input[name="title"]')).toHaveValue(`PW journey post ${stamp}`);

  // The featured image must have persisted, not just been posted.
  const persisted = await page.locator('select[name="image"]').inputValue();
  expect(persisted, 'featured image must persist on the saved post').toBe(imageUrl);
  console.log(`[journey] featured image persisted -> ${persisted}`);

  // 3. Publish through the workflow: draft -> review -> approved -> published.
  for (const action of ['Submit', 'Approve', 'Publish']) {
    const button = page.locator(`button[type="submit"][name="action"][value="${action.toLowerCase()}"]`);
    await expect(button, `workflow action ${action} must be offered`).toBeVisible({ timeout: 15000 });
    await button.click();
    await page.waitForURL(/\/cms-akira-shell\/posts\/.+\/edit/, { timeout: 20000 });
    console.log(`[journey] workflow ${action} -> ok`);
  }
  await expect(page.locator('[data-akira-workflow-state]')).toContainText(/published/i, { timeout: 15000 });

  // 4. The public page must render the image: this is the outcome the journey is about.
  const response = await page.goto(`${TENANT}/posts/${slug}`, { waitUntil: 'domcontentloaded' });
  console.log(`[journey] public GET /posts/${slug} -> ${response?.status()}`);
  expect(response?.status()).toBe(200);
  await expect(page.locator('h1')).toContainText(`PW journey post ${stamp}`);
  const hero = page.locator(`img[src="${imageUrl}"]`).first();
  await expect(hero, 'the published page must render the featured image').toBeVisible({ timeout: 15000 });
});
