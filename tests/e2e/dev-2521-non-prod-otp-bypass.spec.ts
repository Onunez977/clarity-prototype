import { execSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import {
    assertIsolatedCloudOrLocal,
    expectOtpFieldAbsent,
    laravelLogByteOffset,
    loginViaNonProdBypass,
    loginViaOtp,
    readLatestOtpFromLog,
    rewriteForcedHttpsForLocalHttp,
    submitSendOtp,
} from './helpers/auth';

/**
 * DEV-2521 — Non-prod OTP bypass on Filament CustomLoginPage (Playwright).
 *
 * Product under test:
 * - Outside production (`! app()->isProduction()`), an eligible email on
 *   Send OTP authenticates immediately. The OTP field never renders.
 * - Unknown and panel-ineligible emails still fail with Account Not Found
 *   and stay logged out. Same message for both — this is an OTP bypass,
 *   not an authentication bypass.
 * - Production still requires OTP. That branch cannot be browser-asserted
 *   on local / isolated cloud (APP_ENV=local is what turns the bypass on).
 *   Covered by PHPUnit
 *   `test_production_environment_still_requires_otp_end_to_end` only —
 *   do not claim production browser coverage here.
 *
 * Isolated cloud / local only (127.0.0.1 / *.test). assertIsolatedCloudOrLocal
 * runs in beforeAll. Videos on. Reuses loginViaOtp (races redirect vs OTP).
 *
 * Users: Pro `test@tester.com` (company-metrics / LocalDataSeeder), optional
 * E2E_ADMIN_EMAIL / E2E_CLIENT_EMAIL / E2E_INELIGIBLE_EMAIL, else
 * tests/e2e/helpers/seed-dev-2521-users.php.
 */
test.use({
    video: 'on',
    storageState: { cookies: [], origins: [] },
});

type SeededUsers = {
    adminEmail?: string;
    ineligibleEmail?: string;
    clientEmail?: string;
    adminCanAccessApp?: boolean;
    ineligibleCanAccessAny?: boolean;
    clientCanAccessClient?: boolean;
};

let seeded: SeededUsers = {};
let seedError: string | undefined;

function seedDev2521Users(): SeededUsers {
    const script = resolve(process.cwd(), 'tests/e2e/helpers/seed-dev-2521-users.php');

    try {
        const out = execSync(`php ${JSON.stringify(script)}`, {
            encoding: 'utf8',
            timeout: 60_000,
        });
        const jsonLine = out
            .trim()
            .split('\n')
            .filter((line) => line.startsWith('{'))
            .at(-1);

        return JSON.parse(jsonLine ?? '{}') as SeededUsers;
    } catch (error) {
        seedError = error instanceof Error ? error.message : String(error);

        return {};
    }
}

function adminEmail(): string {
    return process.env.E2E_ADMIN_EMAIL ?? seeded.adminEmail ?? 'test@tester.com';
}

function ineligibleEmail(): string | undefined {
    return process.env.E2E_INELIGIBLE_EMAIL ?? seeded.ineligibleEmail;
}

function clientEmail(): string | undefined {
    return process.env.E2E_CLIENT_EMAIL ?? seeded.clientEmail;
}

async function expectAccountNotFoundLoggedOut(page: Page, email: string): Promise<void> {
    await submitSendOtp(page, email, 'app');
    await expect(page.getByText('Account Not Found')).toBeVisible({ timeout: 15_000 });
    await expectOtpFieldAbsent(page);
    expect(page.url(), 'must stay on the login page').toMatch(/\/login/);

    const guest = await page.request.get('/app', { maxRedirects: 0, failOnStatusCode: false });
    const location = guest.headers()['location'] ?? '';

    expect(
        [301, 302, 303, 307, 308].includes(guest.status()) || location.includes('/login'),
        `guest /app must not authenticate (status ${guest.status()}, location ${location})`,
    ).toBeTruthy();

    if (location !== '') {
        expect(location).toMatch(/login/);
    }
}

test.describe('DEV-2521 non-prod OTP bypass', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertIsolatedCloudOrLocal(process.env.BASE_URL ?? process.env.APP_URL);
        execSync('php artisan cache:clear --no-interaction', { stdio: 'ignore' });
        seeded = seedDev2521Users();
    });

    test.beforeEach(async ({ page }) => {
        await rewriteForcedHttpsForLocalHttp(page);
        await page.context().clearCookies();
    });

    test('A. Non-prod app panel: eligible email authenticates without OTP field', async ({
        page,
    }) => {
        const email = adminEmail();

        if (seeded.adminCanAccessApp === false && !process.env.E2E_ADMIN_EMAIL) {
            test.skip(
                true,
                `Pro smoke user ${email} is not app-panel eligible and could not be seeded${seedError ? ` (${seedError})` : ''}.`,
            );
        }

        await loginViaNonProdBypass(page, email, 'app');
        await expect(page).toHaveURL(/\/app(\/|$|\?)/);
        await expectOtpFieldAbsent(page);
    });

    test('B. Non-prod: unknown email → Account Not Found; stays logged out', async ({
        page,
    }) => {
        await expectAccountNotFoundLoggedOut(
            page,
            `unknown-dev-2521-${Date.now()}@example.invalid`,
        );
    });

    test('C. Non-prod: existing panel-ineligible user → Account Not Found', async ({
        page,
    }) => {
        const email = ineligibleEmail();

        if (!email || seeded.ineligibleCanAccessAny === true) {
            test.skip(
                true,
                `Panel-ineligible user is not seedable${seedError ? `: ${seedError}` : ' (no E2E_INELIGIBLE_EMAIL / seed-dev-2521-users.php output)'}.`,
            );

            return;
        }

        await expectAccountNotFoundLoggedOut(page, email);
    });

    test('D. Non-prod client panel: eligible client-org user authenticates without OTP', async ({
        page,
    }) => {
        const email = clientEmail();

        if (!email || seeded.clientCanAccessClient === false) {
            test.skip(
                true,
                `Client-org smoke user is not available${seedError ? `: ${seedError}` : ' (no E2E_CLIENT_EMAIL / seed-dev-2521-users.php output)'}.`,
            );

            return;
        }

        await loginViaNonProdBypass(page, email, 'client');
        await expect(page).toHaveURL(/\/client(\/|$|\?)/);
        expect(page.url()).not.toMatch(/\/app(\/|$|\?)/);
        await expectOtpFieldAbsent(page);
    });

    test('E. Non-prod: OTP input absent from DOM before submit on /app/login', async ({
        page,
    }) => {
        await rewriteForcedHttpsForLocalHttp(page);
        await page.goto('/app/login', { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => typeof window.Livewire !== 'undefined');
        await expect(page.getByRole('button', { name: 'Send OTP' })).toBeVisible();
        await expectOtpFieldAbsent(page);
        await expect(page.getByLabel(/email/i)).toBeVisible();
    });

    test('F. Non-prod: successful bypass writes no OTP to laravel.log', async ({ page }) => {
        const email = adminEmail();

        if (seeded.adminCanAccessApp === false && !process.env.E2E_ADMIN_EMAIL) {
            test.skip(true, `Pro smoke user ${email} is not available for the log assertion.`);
        }

        const offset = laravelLogByteOffset();
        await loginViaOtp(page, email, 'app');
        await expect(page).not.toHaveURL(/\/login/);
        expect(
            readLatestOtpFromLog(offset),
            'non-prod bypass must not write a 6-digit otp_code to laravel.log',
        ).toBeUndefined();
        await expectOtpFieldAbsent(page);
    });

    test('F2. Optional: rateLimit still fires on the 6th Send OTP', async ({ page }) => {
        execSync('php artisan cache:clear --no-interaction', { stdio: 'ignore' });

        const unknown = `ratelimit-dev-2521-${Date.now()}@example.invalid`;

        for (let attempt = 1; attempt <= 5; attempt++) {
            await submitSendOtp(page, unknown, 'app');
            await expect(page.getByText('Account Not Found')).toBeVisible({ timeout: 15_000 });
        }

        await submitSendOtp(page, unknown, 'app');
        await expect(page.getByText(/too many login attempts/i)).toBeVisible({
            timeout: 15_000,
        });
        await expectOtpFieldAbsent(page);
        expect(page.url()).toMatch(/\/login/);
    });
});
