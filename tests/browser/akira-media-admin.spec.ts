import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';
const TENANT_USER = process.env.TENANT_USER ?? 'charlienacario884';
const TENANT_PASS = process.env.TENANT_PASS ?? 'iKabud6123!#';

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT}/login`);
    await page.fill('#username', TENANT_USER);
    await page.fill('#password', TENANT_PASS);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/cms-akira-shell(?:\/|$)/, { timeout: 20000 });
}

async function expectAdminPage(page: Page, path: string, text: RegExp): Promise<void> {
    const response = await page.goto(`${TENANT}${path}`, { waitUntil: 'domcontentloaded' });
    console.log(`[media/no-lockout] ${path} -> ${response?.status()}`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).toContainText(text);
}

test('tenant admin can list, upload, and delete media; provider rejects a disallowed type', async ({ page }) => {
    await login(page);

    const initial = await page.goto(`${TENANT}/cms-akira-shell/media`, { waitUntil: 'domcontentloaded' });
    console.log(`[media] authorised GET /cms-akira-shell/media -> ${initial?.status()}`);
    expect(initial?.status()).toBe(200);
    await expect(page.locator('h1')).toHaveText('Media');
    await expect(page.locator('form[data-akira-media-upload]')).toBeVisible();

    // No accept= filter is used: this reaches akira.media.upload@1 and proves
    // the provider's MIME allowlist refuses the content rather than the UI hiding it.
    await page.locator('input[name="media_file"]').setInputFiles({
        name: 'disallowed-media.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('not an allowed media type'),
    });
    const refusedResponse = page.waitForResponse((response) =>
        response.url().includes('/cms-akira-shell/media')
        && response.request().method() === 'POST'
    );
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    const refused = await refusedResponse;
    console.log(`[media] disallowed text upload -> ${refused.status()}`);
    expect(refused.status()).toBe(422);
    await expect(page.getByRole('alert')).toContainText(/mime_type is not allowed/i);
    await expect(page.locator('[data-akira-media-row]').filter({ hasText: 'disallowed-media.txt' })).toHaveCount(0);

    const filename = `akira-media-e3-${Date.now()}.png`;
    const alt = 'Akira media browser assertion';
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await page.locator('input[name="media_file"]').setInputFiles({ name: filename, mimeType: 'image/png', buffer: png });
    await page.locator('input[name="alt"]').fill(alt);
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    await page.waitForURL(/\/cms-akira-shell\/media\?saved=upload$/, { timeout: 20000 });

    const row = page.locator('[data-akira-media-row]').filter({ hasText: filename });
    await expect(row, 'uploaded file must appear in the governed library result').toHaveCount(1);
    await expect(row).toContainText(alt);
    await expect(row.locator('img')).toHaveCount(1);
    console.log(`[media] upload listed: ${filename}`);

    page.once('dialog', (dialog) => void dialog.accept());
    await row.getByRole('button', { name: 'Delete', exact: true }).click();
    await page.waitForURL(/\/cms-akira-shell\/media\?saved=delete$/, { timeout: 20000 });
    await expect(page.locator('[data-akira-media-row]').filter({ hasText: filename }), 'deleted file must disappear from the governed library result').toHaveCount(0);
    await expect(page.getByRole('status')).toContainText(/media deleted/i);
    console.log(`[media] delete removed: ${filename}`);

    await expectAdminPage(page, '/cms-akira-shell', /cms akira dashboard/i);
    await expectAdminPage(page, '/cms-akira-shell/posts', /posts/i);
    await expectAdminPage(page, '/cms-akira-shell/categories', /categor/i);
    await expectAdminPage(page, '/cms-akira-shell/content-types', /content type/i);
    await expectAdminPage(page, '/cms-akira-shell/permissions', /permission/i);
    await expectAdminPage(page, '/cms-akira-shell/users', /user/i);
});

test('fresh anonymous shell login entry still reaches the login form', async ({ page }) => {
    const response = await page.goto(`${TENANT}/cms-akira-shell/login`, { waitUntil: 'domcontentloaded' });
    console.log(`[media/no-lockout] anonymous login -> ${response?.status()} at ${page.url()}`);
    expect(response?.status()).not.toBe(403);
    await page.waitForURL(/\/login$/, { timeout: 20000 });
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
});
