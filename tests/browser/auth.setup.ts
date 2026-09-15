// Global authentication setup for the browser suite.
//
// The kernel login limiter permits 5 attempts per 300s window. Every authenticated
// spec used to submit the login form itself (7 POSTs in this tree; 9 as the chair
// measured for the failing run), so the limiter refused the later attempts and the
// specs failed with a timeout that reads exactly like an authorisation regression.
// This logs in once and persists the session; every authenticated spec in the
// chromium project reuses that storageState and makes no login POST of its own.
//
// Why globalSetup rather than a `setup` project: a Playwright `setup` project is a
// normal test, so it is counted in `--list` and in the pass total (verified locally:
// one setup test + the specs reported 10). The contract's acceptance requires the
// suite to list and report exactly 9 tests, so the setup must not itself be a test.
// Global setup is Playwright's other official auth mechanism and produces the same
// storageState; the chromium project consumes it.
//
// Credentials come from the git-ignored .env loaded by playwright.config.js. There is
// deliberately no literal fallback: an embedded admin password once reached 8 commits
// of history (see live-hosts.spec.ts).

import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';

const TENANT = process.env.TENANT_URL ?? process.env.APP_URL ?? 'http://akiracms.test';
const TENANT_USER = process.env.TENANT_USER ?? '';
const TENANT_PASS = process.env.TENANT_PASS ?? '';
const STORAGE_STATE = process.env.PW_TENANT_STORAGE_STATE
    ?? join(process.cwd(), 'tests', 'browser', '.auth', 'tenant.json');

export default async function globalSetup(): Promise<void> {
    if (TENANT_USER === '' || TENANT_PASS === '') {
        throw new Error(
            'TENANT_USER/TENANT_PASS are not set. playwright.config.js loads them from the '
            + 'git-ignored .env; run the suite from the repository root.',
        );
    }

    const browser = await chromium.launch();
    const context = await browser.newContext();
    const page = await context.newPage();

    try {
        await page.goto(`${TENANT}/login`);
        await page.fill('#username', TENANT_USER);
        await page.fill('#password', TENANT_PASS);
        await page.locator('button[type="submit"]').first().click();
        await page.waitForURL(/\/cms-akira-shell(?:\/|$)/, { timeout: 20000 });

        mkdirSync(dirname(STORAGE_STATE), { recursive: true });
        await context.storageState({ path: STORAGE_STATE });
        console.log(`[auth.setup] tenant session saved -> ${STORAGE_STATE}`);
    } finally {
        await browser.close();
    }
}
