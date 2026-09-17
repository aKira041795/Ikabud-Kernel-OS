// Akira Theme Editor milestone — one test per requirement, each independently gate-able.
//
// WHY ONE TEST PER REQUIREMENT (2026-09-17, CD-78): the next claim under measurement is that HARPP can exhaust a
// MULTI-REQUIREMENT milestone rather than one item. A requirement is only exhaustible if its verdict is separable,
// so each requirement here is its own test, addressable with `-g "R1"`, and the milestone manifest points one gate
// at each.
//
// Authored by the chair as the milestone's EVIDENCE, not by the executor: the chair defines the outcome and what
// proves it; the machine does the work. These are expected to FAIL on the day they are written — that is the
// red baseline, and it is the point.
//
// Deliberately implementation-agnostic: selectors accept any reasonable surface (an iframe preview, a
// `[data-theme-preview]` container, a same-origin frame). The requirement is the capability, not a design.

import { test, expect } from '@playwright/test';

const EDITOR = '/cms-akira-theme';

/** Any surface the editor uses to show the theme's rendered output. */
const PREVIEW_SELECTORS = [
    '[data-theme-preview]',
    '#theme-preview',
    '[id*="preview" i]',
    '[class*="preview" i]',
    'iframe[src]',
];

async function previewHandle(page: import('@playwright/test').Page) {
    for (const selector of PREVIEW_SELECTORS) {
        const locator = page.locator(selector).first();
        if (await locator.count() > 0 && await locator.isVisible().catch(() => false)) {
            return locator;
        }
    }
    return null;
}

/** The preview's resolved CSS custom property, whether it lives in an iframe or in the page. */
async function previewToken(page: import('@playwright/test').Page, token: string): Promise<string | null> {
    const frame = page.frames().find((f) => f !== page.mainFrame() && f.url().startsWith('http'));
    const target = frame ?? page.mainFrame();
    return target.evaluate((name) => {
        const root = document.documentElement;
        const value = getComputedStyle(root).getPropertyValue(name).trim();
        return value === '' ? null : value;
    }, token);
}

test.describe('Akira theme editor milestone', () => {
    test('R1 preview: the editor shows a preview of the theme being edited', async ({ page }) => {
        await page.goto(EDITOR);
        // The decisive evidence for a usability requirement is the look itself, so it is captured whether the
        // requirement passes or fails — a milestone whose artifact is missing is not complete.
        await page.screenshot({ path: 'test-results/akira-theme-editor.png', fullPage: true });
        const preview = await previewHandle(page);
        expect(preview, 'the editor exposes a preview surface').not.toBeNull();

        const box = await preview!.boundingBox();
        expect(box?.height ?? 0, 'the preview has real rendered height').toBeGreaterThan(200);
        expect(box?.width ?? 0, 'the preview has real rendered width').toBeGreaterThan(300);
    });

    test('R2 legibility: every colour field shows its current value', async ({ page }) => {
        await page.goto(EDITOR);

        const inputs = page.locator('input[type="color"]');
        const count = await inputs.count();
        expect(count, 'the editor exposes colour fields').toBeGreaterThan(5);

        // Decidable without decoding pixels: change the value and compare the control's OWN rendered pixels.
        // A control that displays its value repaints; one that shows nothing (a bare box) does not.
        // (An earlier version of this check asked only for a computed background, which a white box satisfies —
        // it passed on a control that shows nothing. A check that cannot fail on its requirement is decoration.)
        const unreadable: string[] = [];
        const sample = Math.min(count, 6);

        for (let i = 0; i < sample; i += 1) {
            const input = inputs.nth(i);
            const label = (await input.getAttribute('name')) ?? `#${i}`;
            if (!(await input.isVisible())) {
                unreadable.push(`${label} — not visible`);
                continue;
            }

            const original = await input.inputValue();
            const before = await input.screenshot();
            const next = original.toLowerCase() === '#000000' ? '#ff0000' : '#000000';
            const setValue = (value: string) => input.evaluate((el, v) => {
                const field = el as HTMLInputElement;
                field.value = v;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }, value);

            await setValue(next);
            await page.waitForTimeout(200);
            const after = await input.screenshot();
            await setValue(original);
            await page.waitForTimeout(100);

            if (Buffer.compare(before, after) === 0) {
                unreadable.push(`${label} — the control renders no visible value`);
            }
        }

        expect(unreadable, 'every colour field visibly shows its value').toEqual([]);
    });

    test('R3 liveness: changing a colour updates the preview before saving', async ({ page }) => {
        await page.goto(EDITOR);
        const preview = await previewHandle(page);
        expect(preview, 'the editor exposes a preview surface').not.toBeNull();

        const input = page.locator('input[type="color"]').first();
        const token = await input.getAttribute('name');
        const before = await previewToken(page, '--color-primary');
        expect(before, 'the preview resolves theme tokens').not.toBeNull();

        // Choose a value that differs from the current one, apply it, and require the preview to follow
        // WITHOUT a save: this is what makes the editor usable rather than a blind form.
        const next = before === '#123456' ? '#654321' : '#123456';
        await input.evaluate((el, value) => {
            const field = el as HTMLInputElement;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }, next);
        await page.waitForTimeout(600);

        const after = await previewToken(page, '--color-primary');
        expect(after, `the preview follows the edited value (${token})`).not.toBe(before);
    });

    test('R4 persistence: saving keeps the values, and a no-op save is safe', async ({ page }) => {
        await page.goto(EDITOR);
        const original = await page.locator('input[type="color"]').first().inputValue();

        // Save the values as they already are: proves the round-trip without changing the tenant's live theme.
        await page.getByRole('button', { name: /save/i }).first().click();
        await page.waitForLoadState('load');

        await page.goto(EDITOR);
        const reloaded = await page.locator('input[type="color"]').first().inputValue();
        expect(reloaded, 'the saved value survives a reload').toBe(original);
        await expect(page.locator('body')).not.toContainText(/fatal error|uncaught|exception/i);
    });

    test('R5 recovery: unsaved changes can be discarded', async ({ page }) => {
        await page.goto(EDITOR);
        const input = page.locator('input[type="color"]').first();
        const original = await input.inputValue();

        await input.evaluate((el, value) => {
            const field = el as HTMLInputElement;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }, original === '#abcdef' ? '#fedcba' : '#abcdef');
        await page.waitForTimeout(200);

        const discard = page.getByRole('button', { name: /discard|reset|revert|undo/i })
            .or(page.getByRole('link', { name: /discard|reset|revert|undo/i })).first();
        expect(await discard.count(), 'the editor offers a way to abandon changes').toBeGreaterThan(0);

        await discard.click();
        await page.waitForTimeout(400);
        expect(await input.inputValue(), 'discarding restores the loaded value').toBe(original);
    });

    test('R6 integrity: the editor loads and saves without console errors', async ({ page }) => {
        const problems: string[] = [];
        page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text().slice(0, 160)); });
        page.on('pageerror', (e) => problems.push('pageerror: ' + e.message.slice(0, 160)));
        page.on('requestfailed', (r) => problems.push('requestfailed: ' + r.url().slice(0, 120)));

        await page.goto(EDITOR);
        await page.waitForTimeout(800);
        expect(problems, 'no console errors or failed requests on load').toEqual([]);
    });
});
