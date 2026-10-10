import { closeSync, existsSync, openSync, readSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Filament app-panel login helper for isolated Clarity Playwright e2e.
 *
 * Mirrors Feature / ResourceTestCase auth conceptually: an ADMIN is
 * `createUser(role: RoleEnum::ADMIN)` (LocalDataSeeder user id 1,
 * test@tester.com). Non-admin is `createUser(role: [])` — no RoleEnum::ADMIN.
 *
 * Resolution order:
 * 1. Already authenticated (E2E_STORAGE_STATE)
 * 2. GET /__qa/login/{id} — same local-only QA bypass Feature/QA uses
 *    (APP_ENV=local + DEV_LOGIN_ENABLED + loopback + *.test host)
 * 3. OTP at /app/login when E2E_*_EMAIL is set (MAIL_MAILER=log). Outside production
 *    the panel skips the OTP step (DEV-2521), so this resolves in one submit and the
 *    OTP field never renders — still a real login that plants no `admin_impersonator`.
 *
 * Never impersonates production accounts. Never hits production hosts.
 */
const PRODUCTION_HOST_RE = /(^|\.)insightlpr\.io$/i;

export function assertNotProduction(baseURL: string | undefined): void {
    if (!baseURL) {
        return;
    }

    let hostname: string;

    try {
        hostname = new URL(baseURL).hostname;
    } catch {
        throw new Error(`Invalid BASE_URL / APP_URL: ${baseURL}`);
    }

    if (PRODUCTION_HOST_RE.test(hostname)) {
        throw new Error(`Refusing to run e2e against production host: ${hostname}`);
    }
}

/**
 * Isolated cloud / local only (127.0.0.1, localhost, *.test).
 * Production OTP is covered by PHPUnit
 * `test_production_environment_still_requires_otp_end_to_end` — do not
 * claim browser coverage of that branch.
 */
export function assertIsolatedCloudOrLocal(baseURL: string | undefined): void {
    assertNotProduction(baseURL);

    if (!baseURL) {
        return;
    }

    let hostname: string;

    try {
        hostname = new URL(baseURL).hostname;
    } catch {
        throw new Error(`Invalid BASE_URL / APP_URL: ${baseURL}`);
    }

    const isolated =
        hostname === '127.0.0.1' ||
        hostname === 'localhost' ||
        hostname.endsWith('.test');

    if (!isolated) {
        throw new Error(
            `DEV-2521 e2e is isolated cloud / local only (127.0.0.1 / *.test). Refusing host: ${hostname}`,
        );
    }
}

const HTTPS_REWRITE_APPLIED = Symbol.for('e2e.rewriteForcedHttps');

/**
 * AppServiceProvider unconditionally URL::forceScheme('https'). Isolated
 * cloud artisan serve is HTTP, so Livewire/Filament assets  fail to load
 * unless we fulfill those https://127.0.0.1 requests over http://.
 * No-op when BASE_URL is already https (Herd *.test).
 */
export async function rewriteForcedHttpsForLocalHttp(page: Page): Promise<void> {
    if ((page as Page & { [HTTPS_REWRITE_APPLIED]?: boolean })[HTTPS_REWRITE_APPLIED]) {
        return;
    }

    const baseURL = page.context().baseURL ?? process.env.BASE_URL ?? process.env.APP_URL;

    if (!baseURL) {
        return;
    }

    const parsed = new URL(baseURL);

    if (parsed.protocol !== 'http:') {
        return;
    }

    const httpsOrigin = `https://${parsed.host}`;
    const httpOrigin = parsed.origin;

    // forceScheme('https') rewrites asset and Livewire URLs. On an HTTP cloud
    // host those become cross-scheme requests, and SameSite=Lax cookies are not
    // sent. Rewrite the documents back to the HTTP origin so the real login
    // form can post on the same origin.
    await page.route(`${httpOrigin}/**`, async (route) => {
        if (route.request().resourceType() !== 'document') {
            await route.fallback();

            return;
        }

        const response = await route.fetch({ maxRedirects: 0 });
        const headers = { ...response.headers() };
        const location = headers.location ?? headers.Location;

        if (typeof location === 'string' && location.startsWith(httpsOrigin)) {
            headers.location = location.replace(httpsOrigin, httpOrigin);
            delete headers.Location;
        }

        const contentType = headers['content-type'] ?? '';

        if (!contentType.includes('text/html')) {
            await route.fulfill({
                status: response.status(),
                headers,
                body: await response.body(),
            });

            return;
        }

        const body = (await response.text()).replaceAll(httpsOrigin, httpOrigin);

        await route.fulfill({
            status: response.status(),
            headers,
            body,
        });
    });

    await page.route(`${httpsOrigin}/**`, async (route) => {
        const request = route.request();
        const httpUrl = request.url().replace(/^https:/, 'http:');

        try {
            // page.request.fetch() uses a separate cookie jar, so Livewire's
            // CSRF cookie never matches the token and the POST returns 419.
            // route.fetch() keeps the browser request's cookies and body.
            const response = await route.fetch({
                url: httpUrl,
                maxRedirects: 0,
            });

            await route.fulfill({ response });
        } catch {
            await route.abort();
        }
    });

    (page as Page & { [HTTPS_REWRITE_APPLIED]?: boolean })[HTTPS_REWRITE_APPLIED] = true;
}

export type FilamentPanel = 'app' | 'client';

export function otpField(page: Page): Locator {
    return page.getByLabel(/^otp$/i);
}

export function otpDomInputs(page: Page): Locator {
    return page.locator('input[name="data.otp"], input[id="data.otp"]');
}

export async function expectOtpFieldAbsent(page: Page): Promise<void> {
    await expect(page.getByRole('textbox', { name: /^otp$/i })).toHaveCount(0);
    await expect(otpField(page)).toHaveCount(0);
    await expect(otpDomInputs(page)).toHaveCount(0);
}

export async function submitSendOtp(
    page: Page,
    email: string,
    panel: FilamentPanel = 'app',
): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);
    await page.goto(`/${panel}/login`, { waitUntil: 'domcontentloaded' });

    if (!page.url().includes('/login')) {
        return;
    }

    await page.waitForFunction(() => typeof window.Livewire !== 'undefined');
    await page.getByRole('textbox', { name: /email/i }).fill(email);

    const livewire = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && response.url().includes('livewire'),
        { timeout: 15_000 },
    );

    await page.getByRole('button', { name: 'Send OTP' }).click();
    await livewire.catch(() => undefined);
}

export function laravelLogByteOffset(): number {
    const logPath = resolve(process.cwd(), 'storage/logs/laravel.log');

    return existsSync(logPath) ? statSync(logPath).size : 0;
}

/**
 * Non-prod bypass (DEV-2521): eligible email authenticates on Send OTP and
 * the OTP field never renders. Fails if the OTP input becomes visible.
 */
export async function loginViaNonProdBypass(
    page: Page,
    email: string,
    panel: FilamentPanel = 'app',
): Promise<void> {
    const field = otpField(page);
    let otpBecameVisible = false;

    await submitSendOtp(page, email, panel);

    await Promise.race([
        page.waitForURL((url) => !url.pathname.includes('/login'), {
            timeout: 15_000,
            waitUntil: 'commit',
        }),
        field.waitFor({ state: 'visible', timeout: 15_000 }).then(() => {
            otpBecameVisible = true;
        }),
    ]);

    expect(otpBecameVisible, 'OTP field must not appear on the DEV-2521 non-prod bypass').toBe(
        false,
    );
    await expect(page).not.toHaveURL(/\/login/);
    await expectOtpFieldAbsent(page);
}

export function adminUserId(): string {
    return process.env.E2E_ADMIN_USER_ID ?? '1';
}

export function nonAdminUserId(): string | undefined {
    return process.env.E2E_NON_ADMIN_USER_ID;
}

export async function loginAsAdmin(page: Page): Promise<void> {
    await loginAs(page, {
        userId: adminUserId(),
        email: process.env.E2E_ADMIN_EMAIL,
    });
}

export async function loginAsNonAdmin(page: Page): Promise<void> {
    const userId = nonAdminUserId();

    if (!userId && !process.env.E2E_NON_ADMIN_EMAIL) {
        throw new Error(
            'Set E2E_NON_ADMIN_USER_ID (factory user without RoleEnum::ADMIN) or E2E_NON_ADMIN_EMAIL.',
        );
    }

    await loginAs(page, {
        userId: userId ?? '0',
        email: process.env.E2E_NON_ADMIN_EMAIL,
        skipQaLogin: !userId,
    });
}

async function loginAs(
    page: Page,
    options: { userId: string; email?: string; skipQaLogin?: boolean },
): Promise<void> {
    const baseURL = page.context().baseURL ?? process.env.BASE_URL ?? process.env.APP_URL;
    assertNotProduction(baseURL);

    if (await isAuthenticated(page)) {
        return;
    }

    if (!options.skipQaLogin) {
        const qaPath = process.env.E2E_QA_LOGIN_PATH ?? '/__qa/login';
        const response = await page.goto(`${qaPath}/${options.userId}`, {
            waitUntil: 'domcontentloaded',
        });

        const redirectedToLogin =
            page.url().includes('/login') || page.url().includes('/__qa/');
        const accepted =
            response &&
            (response.ok() || [301, 302, 303, 307, 308].includes(response.status()));

        // A catch-all 302 to /app/login is not a successful QA login — fall through
        // to OTP. The QA route itself redirects to /admin after planting the session.
        if (accepted && !redirectedToLogin) {
            await page.waitForURL((url) => !url.pathname.includes('/login') && !url.pathname.includes('/__qa/'));
            return;
        }
    }

    if (options.email) {
        await loginViaOtp(page, options.email);

        return;
    }

    throw new Error(
        'Could not log in. Isolated cloud needs APP_ENV=local, DEV_LOGIN_ENABLED=true, a *.test host from loopback, and a seeded ADMIN (RoleEnum::ADMIN, typically user id 1). Or set E2E_STORAGE_STATE / E2E_ADMIN_EMAIL.',
    );
}

async function isAuthenticated(page: Page): Promise<boolean> {
    try {
        const response = await page.goto('/app', { waitUntil: 'domcontentloaded' });

        if (!response) {
            return false;
        }

        return !page.url().includes('/login') && response.status() < 400;
    } catch {
        return false;
    }
}

export const QA_LOGIN_PRO_GATE_BYPASS =
    'GET /__qa/login/{id} sets session admin_impersonator unconditionally and bypasses the Missed Opportunities Pro gate. Non-Pro locked-card scenarios must sign in at /app/login with E2E_NON_PRO_EMAIL. Outside production the panel skips the OTP step (DEV-2521), so no E2E_OTP is needed there; in production set E2E_OTP or use a log-backed OTP. Do not use the QA bypass.';

/**
 * Org ADMIN on a non-Pro org. Always signs in through the real /app/login form —
 * with the emailed OTP in production, or in one submit outside it, where the panel
 * skips the OTP step (DEV-2521). Either way it is a genuine Filament login.
 * Never calls /__qa/login — that path plants admin_impersonator and would
 * unlock the card under test.
 */
export async function loginAsNonProOrgAdmin(page: Page): Promise<void> {
    const email = process.env.E2E_NON_PRO_EMAIL;

    if (!email) {
        throw new Error(QA_LOGIN_PRO_GATE_BYPASS);
    }

    const baseURL = page.context().baseURL ?? process.env.BASE_URL ?? process.env.APP_URL;
    assertNotProduction(baseURL);

    await page.context().clearCookies();
    await loginViaOtp(page, email);
}

export async function loginViaOtp(
    page: Page,
    email: string,
    panel: FilamentPanel = 'app',
): Promise<void> {
    const logOffset = laravelLogByteOffset();

    await submitSendOtp(page, email, panel);

    if (!page.url().includes('/login')) {
        return;
    }

    // DEV-2521: outside production the panel skips the OTP step entirely and the submit
    // authenticates straight away, so the OTP field never renders. That is still a real
    // Filament login and it plants no `admin_impersonator`, which is the property these
    // specs need — so accept it and return. Only wait for the OTP field if we are still
    // on /login after the submit.
    const field = otpField(page);

    await Promise.race([
        page.waitForURL((url) => !url.pathname.includes('/login'), {
            timeout: 15_000,
            waitUntil: 'commit',
        }).catch(() => undefined),
        field.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined),
    ]);

    if (!page.url().includes('/login')) {
        return;
    }

    await expect(field).toBeVisible({ timeout: 15_000 });

    let otp = process.env.E2E_OTP;

    if (!otp) {
        await expect
            .poll(() => readLatestOtpFromLog(logOffset) ?? '', { timeout: 10_000 })
            .toMatch(/^\d{6}$/);
        otp = readLatestOtpFromLog(logOffset);
    }

    if (!otp) {
        throw new Error(
            'OTP field is visible but E2E_OTP is unset and storage/logs/laravel.log has no new 6-digit otp_code (MAIL_MAILER=log).',
        );
    }

    await field.fill(otp);
    await page.getByRole('button', { name: 'Login' }).click();

    const invalid = page.getByText(/invalid one time password/i);
    const rateLimited = page.getByText(/too many/i);

    await Promise.race([
        page.waitForURL((url) => !url.pathname.includes('/login')),
        invalid.waitFor({ state: 'visible', timeout: 15_000 }).then(() => {
            throw new Error(`OTP ${otp} was rejected for ${email}.`);
        }),
        rateLimited.waitFor({ state: 'visible', timeout: 15_000 }).then(() => {
            throw new Error(`Login rate-limited for ${email}.`);
        }),
    ]);
}

export function readLatestOtpFromLog(afterByte = 0): string | undefined {
    const logPath = resolve(process.cwd(), 'storage/logs/laravel.log');

    if (!existsSync(logPath)) {
        return undefined;
    }

    const size = statSync(logPath).size;

    if (size <= afterByte) {
        return undefined;
    }

    const fd = openSync(logPath, 'r');
    const length = size - afterByte;
    const buffer = Buffer.alloc(length);

    try {
        readSync(fd, buffer, 0, length, afterByte);
    } finally {
        closeSync(fd);
    }

    const chunk = buffer.toString('utf8');
    const tagged = [...chunk.matchAll(/otp_code"[^>]*>\s*([0-9]{6})/gi)].map((match) => match[1]);

    if (tagged.length > 0) {
        return tagged.at(-1);
    }

    const generic = [...chunk.matchAll(/\b([0-9]{6})\b/g)].map((match) => match[1]);

    return generic.at(-1);
}
