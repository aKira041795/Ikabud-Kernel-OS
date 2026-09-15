import { expect, test, type Locator, type Page } from '@playwright/test';

const VIEWPORTS = [
    { name: 'desktop-900', width: 1280, height: 900 },
    { name: 'short-600', width: 1280, height: 600 },
] as const;

function pathname(href: string): string {
    return new URL(href, 'http://tenant.invalid').pathname;
}

function screenshotName(viewport: string, route: string): string {
    const pageName = route === '/cms-akira-shell'
        ? 'dashboard'
        : route.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-');
    return `test-results/akira-admin-shell-${viewport}-${pageName}.png`;
}

async function assertNoSignOutCollision(nav: Locator, signOut: Locator): Promise<void> {
    const [navBox, signOutBox] = await Promise.all([nav.boundingBox(), signOut.boundingBox()]);
    expect(navBox, 'the navigation scrollport must have a measurable bounding box').not.toBeNull();
    expect(signOutBox, 'Sign out must have a measurable bounding box').not.toBeNull();

    const collisions: string[] = [];
    for (const link of await nav.getByRole('link').all()) {
        const [label, box] = await Promise.all([
            link.innerText(),
            link.boundingBox(),
        ]);
        expect(box, `navigation link ${JSON.stringify(label.trim())} must have a measurable bounding box`).not.toBeNull();
        if (box && navBox && signOutBox) {
            // A scrolled element's raw DOMRect extends beneath its overflow
            // clip. Intersect it with the navigation scrollport first so this
            // compares the link's browser-visible bounding box with Sign out.
            const visible = {
                left: Math.max(box.x, navBox.x),
                right: Math.min(box.x + box.width, navBox.x + navBox.width),
                top: Math.max(box.y, navBox.y),
                bottom: Math.min(box.y + box.height, navBox.y + navBox.height),
            };
            if (visible.left < visible.right && visible.top < visible.bottom
                && visible.left < signOutBox.x + signOutBox.width
                && visible.right > signOutBox.x
                && visible.top < signOutBox.y + signOutBox.height
                && visible.bottom > signOutBox.y) {
                collisions.push(label.trim());
            }
        }
    }

    expect(collisions, `nav links colliding with Sign out: ${collisions.join(', ') || '(none)'}`).toEqual([]);
}

async function assertShell(page: Page, route: string, viewportName: string): Promise<void> {
    const nav = page.getByRole('navigation', { name: 'Akira administration' });
    await expect(nav).toBeVisible();
    const navLinks = nav.getByRole('link');
    expect(await navLinks.count(), 'the real shared navigation must contain links').toBeGreaterThan(0);

    const sidebar = nav.locator('xpath=ancestor::aside[1]');
    const signOut = sidebar.getByRole('link', { name: 'Sign out', exact: true });
    await expect(signOut).toBeVisible();
    await expect(sidebar.getByRole('link').last(), 'Sign out must be the last sidebar link in DOM order').toHaveText('Sign out');
    await assertNoSignOutCollision(nav, signOut);

    await expect(page.locator('script[src="https://cdn.tailwindcss.com"]'), 'the page must load exactly one shared Tailwind CDN script').toHaveCount(1);
    if (route === '/cms-akira-theme') {
        await expect(page.locator('[data-theme-studio]'), 'Theme Studio controls must survive the chrome conversion').toBeVisible();
        await expect(page.getByRole('button', { name: 'Save customization' })).toBeVisible();
        expect(await page.locator('form[action="/cms-akira-theme/customize"] input, form[action="/cms-akira-theme/customize"] select').count(),
            'Theme Studio form fields must survive the chrome conversion').toBeGreaterThan(2);
    }
    const active = nav.locator('a[aria-current="page"]');
    await expect(active, 'the shared navigation must expose exactly one active item').toHaveCount(1);
    expect(pathname(await active.getAttribute('href') ?? ''), 'the active navigation destination must match the visited route').toBe(route);

    if (viewportName === 'short-600') {
        const metrics = await nav.evaluate((element) => {
            element.scrollTop = element.scrollHeight;
            return {
                clientHeight: element.clientHeight,
                scrollHeight: element.scrollHeight,
                scrollTop: element.scrollTop,
            };
        });
        expect(metrics.scrollHeight, 'the navigation must overflow into its own scroll area at 600px').toBeGreaterThan(metrics.clientHeight);
        expect(metrics.scrollTop, 'the navigation must be independently scrollable').toBeGreaterThan(0);
        await expect(signOut, 'Sign out must remain visible after the navigation scrolls').toBeInViewport();
        await assertNoSignOutCollision(nav, signOut);
    }

    await page.screenshot({ path: screenshotName(viewportName, route), fullPage: true });
}

for (const viewport of VIEWPORTS) {
    test(`shared admin shell is structurally sound at ${viewport.height}px`, async ({ page }) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const landing = await page.goto('/cms-akira-shell?nocache=1', { waitUntil: 'domcontentloaded' });
        expect(landing?.ok(), 'the dashboard must load successfully').toBeTruthy();

        const nav = page.getByRole('navigation', { name: 'Akira administration' });
        await expect(nav).toBeVisible();
        const destinations = await nav.getByRole('link').evaluateAll((links) => links.map((link) => ({
            href: (link as HTMLAnchorElement).href,
            label: (link.textContent ?? '').trim(),
        })));
        expect(destinations.length, 'sidebar destination discovery must not silently match nothing').toBeGreaterThan(0);

        const routes = [...new Set([
            ...destinations.map(({ href }) => pathname(href)),
            '/cms-akira-theme',
        ])];
        for (const route of routes) {
            const response = await page.goto(`${route}?nocache=1`, { waitUntil: 'domcontentloaded' });
            expect(response?.ok(), `${route} must load successfully`).toBeTruthy();
            expect(pathname(page.url()), `${route} must not redirect away from its admin surface`).toBe(route);
            await assertShell(page, route, viewport.name);
        }
    });
}
