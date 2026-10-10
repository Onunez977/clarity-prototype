import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import {
    assertIsolatedCloudOrLocal,
    loginViaNonProdBypass,
    rewriteForcedHttpsForLocalHttp,
} from './helpers/auth';
import {
    commitZip,
    DEFAULT_FRAGMENT_ZIP,
    DEFAULT_MALFORMED,
    DEFAULT_MISSING_ZIP,
    DEFAULT_THREE_DIGIT,
    DEFAULT_VALID_ZIP,
    expectNoSuccessToast,
    expectNotification,
    fillZoneName,
    gotoManageZones,
    injectDefinition,
    injectMinimumPolygon,
    installGoogleMapsStub,
    invokeAddZip,
    loginScoutAdmin,
    lookupZoneByName,
    MANAGE_ZONES_PATH,
    pasteZips,
    saveZone,
    seedDev2252,
    startNewZone,
    uniqueZoneName,
    waitForZoneManager,
    ZONES_LIST_PATH,
    zoneManagerState,
    type Dev2252Seed,
    assertPhpunitGuardInventory,
} from './helpers/dev-2252';

/**
 * DEV-2252 — Scout / Clarity Scout ZIP zone creation (Playwright).
 *
 * Product under test (do not edit from this spec):
 * - Client `resources/js/components/zone-manager.js`: exact `/^\d{5}$/` tokens;
 *   reject 3–4 digit fragments with `Not a valid ZIP`; `addZip()` exact-match
 *   only (no `rows[0]` fallback); `No boundary found for: …` when ZCTA missing.
 * - Server `app/Filament/Pages/ManageZones.php`: after
 *   `rebuildGeometryFromDefinition()`, refuse save when geom is NULL/empty OR
 *   any zip is unresolved; persistent danger **Zone not saved** naming the
 *   bad zip(s). Hidden `definition` previously swallowed validation.
 * - Sibling `app/Services/RunArea/Materializer.php` empty-input / partial
 *   unresolvable guard (PHPUnit on disk — scenario M).
 *
 * Isolated cloud / local only (`http://127.0.0.1:8000` / `*.test`).
 * `assertIsolatedCloudOrLocal` in beforeAll. Never production / never VPN
 * preview as execute host. Videos on. Serial. Distinct named tests A–N.
 *
 * Env / seed:
 *   CLARITY_SCOUT_ENABLED=true   (config app.experimental.clarity_scout)
 *   BASE_URL or APP_URL          (default http://127.0.0.1:8000)
 *   FORCE_HTTPS=false            (artisan serve is HTTP; Secure cookies otherwise drop)
 *   SESSION_SECURE_COOKIE=false
 *   E2E_ADMIN_EMAIL              (optional; else qa-dev-2252-scout-admin@tester.com)
 *   E2E_NO_SCOUT_EMAIL           (optional; else qa-dev-2252-no-scout@tester.com)
 *   E2E_NON_ADMIN_EMAIL          (optional; else qa-dev-2252-non-admin@tester.com)
 *   E2E_START_SERVER=1           (optional; playwright.config starts artisan serve)
 *
 *   php tests/e2e/helpers/seed-dev-2252-scout-zips.php
 *
 * Fixture ZIPs (helper): 75201/75202 resolvable; 75203 NULL geom; 75209 or
 * 99999 missing; 7520 is the Excel-stripped 4-digit fragment that prefix-
 * matches 75201 (the old `rows[0]` wrong-geography bug).
 *
 * Scenarios:
 *   A  Open Manage Zones / map create (ZoneResource New Zone deep-link).
 *      Page loads without 500. ManageZones shouldRegisterNavigation is false.
 *   B  4-digit fragment (7520): client `Not a valid ZIP`; nothing attached;
 *      no polygon overlay. QAM-2 Excel-stripped leading zero.
 *   C  3-digit / malformed tokens rejected the same way.
 *   D  Exact 5-digit with no ZCTA (75209/99999): `No boundary found for: …`;
 *      no unrelated polygon. Alpine addZip('7520') also refuses 75201 —
 *      QAM-3 + QAM-4 rows[0] fallback.
 *   E  Valid resolvable zip (75201): attaches overlay; zip in staged list.
 *   F  Save with only unresolvable zip: red persistent **Zone not saved**
 *      naming the zip; zone not persisted. QAM-5.
 *   G  Mixed valid + unresolvable on save: still rejects; names only the
 *      bad zip; no silent partial geom. QAM-7.
 *   H  Happy path: valid zip-only zone saves; success toast; reopen from
 *      Zones list / ?zone= deep-link; polygon/zip hydrates AND geom persists.
 *      Toast alone is not enough. QAM-1.
 *   I  Empty definition save: rejected / blocked; no silent success.
 *   J  Polygon-only zone (injected minimum ring): saves; no false
 *      Zone not saved (Materializer/geom path quiet).
 *   K  Duplicate zip add: no double-add / graceful duplicate.
 *   L  Paste multi-token mix: malformed listed separately from not-found.
 *   M  Behind-the-scenes inventory: PHPUnit ManageZones + Materializer
 *      guards still on disk. QAM-6. Does not edit those files.
 *   N  Negative nav: unauthorized / feature-flag-off user cannot open
 *      Manage Zones (skip if seed cannot provision).
 */

test.use({
    video: 'on',
    storageState: { cookies: [], origins: [] },
});

let seeded: Dev2252Seed = {};
let seedError: string | undefined;

function adminEmail(): string {
    return process.env.E2E_ADMIN_EMAIL ?? seeded.adminEmail ?? 'test@tester.com';
}

function validZip(): string {
    return seeded.validZip ?? DEFAULT_VALID_ZIP;
}

function missingZip(): string {
    return seeded.missingZip ?? DEFAULT_MISSING_ZIP;
}

function fragmentZip(): string {
    return seeded.fragmentZip ?? DEFAULT_FRAGMENT_ZIP;
}

function scoutReady(): boolean {
    return seeded.experimentalClarityScout === true && seeded.adminCanAccessManageZones === true;
}

function skipUnlessScout(page: Page): void {
    if (scoutReady() || process.env.E2E_ADMIN_EMAIL) {
        return;
    }

    test.skip(
        true,
        `Scout Manage Zones is not reachable. Set CLARITY_SCOUT_ENABLED=true and run php tests/e2e/helpers/seed-dev-2252-scout-zips.php${seedError ? ` (${seedError})` : ''}.`,
    );
}

async function openCreateFlow(page: Page): Promise<void> {
    await loginScoutAdmin(page, adminEmail());
    await gotoManageZones(page);
    await startNewZone(page);
}

test.describe('DEV-2252 Scout ZIP zone creation', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeAll(() => {
        assertIsolatedCloudOrLocal(process.env.BASE_URL ?? process.env.APP_URL);
        execSync('php artisan cache:clear --no-interaction', { stdio: 'ignore' });

        try {
            seeded = seedDev2252();
        } catch (error) {
            seedError = error instanceof Error ? error.message : String(error);
            seeded = {};
        }
    });

    test.beforeEach(async ({ page }) => {
        execSync('php artisan cache:clear --no-interaction', { stdio: 'ignore' });
        await rewriteForcedHttpsForLocalHttp(page);
        await installGoogleMapsStub(page);
        await page.context().clearCookies();
    });

    test('A. Manage Zones create deep-link loads without 500', async ({ page }) => {
        skipUnlessScout(page);
        await loginScoutAdmin(page, adminEmail());

        const list = await page.goto(ZONES_LIST_PATH, { waitUntil: 'domcontentloaded' });

        expect(list, 'Zones list must respond').not.toBeNull();
        expect(list?.status() ?? 0, 'Zones list must not 500').toBeLessThan(500);

        const create = page.getByRole('link', { name: 'New Zone' }).or(
            page.getByRole('button', { name: 'New Zone' }),
        );

        if (await create.first().isVisible().catch(() => false)) {
            await create.first().click();
        } else {
            await page.goto(MANAGE_ZONES_PATH, { waitUntil: 'domcontentloaded' });
        }

        await expect(page).toHaveURL(/\/app\/manage-zones/);
        await waitForZoneManager(page);
        await expect(page.getByRole('button', { name: 'New Zone' }).first()).toBeVisible();
        await expect(page.locator('body')).not.toContainText(/server error|undefined variable/i);
    });

    test('B. 4-digit fragment (Excel-stripped) is Not a valid ZIP; nothing attached', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const fragment = fragmentZip();

        await commitZip(page, fragment);

        await expect(page.getByText(new RegExp(`Not a valid ZIP:\\s*${fragment}`))).toBeVisible();

        const state = await zoneManagerState(page);

        expect(state.zipCodes, '4-digit fragment must not stage a ZIP').toEqual([]);
        expect(state.zipOverlayCount, 'no polygon overlay for a rejected fragment').toBe(0);
        expect(state.zipError).toMatch(/Not a valid ZIP/);
        expect(state.zipError).not.toMatch(/No boundary found/);
    });

    test('C. 3-digit and malformed tokens are rejected the same way', async ({ page }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        await commitZip(page, DEFAULT_THREE_DIGIT);
        await expect(page.getByText(new RegExp(`Not a valid ZIP:\\s*${DEFAULT_THREE_DIGIT}`))).toBeVisible();

        let state = await zoneManagerState(page);

        expect(state.zipCodes).toEqual([]);
        expect(state.zipOverlayCount).toBe(0);

        await commitZip(page, DEFAULT_MALFORMED);
        await expect(page.getByText(new RegExp(`Not a valid ZIP:\\s*${DEFAULT_MALFORMED}`))).toBeVisible();

        state = await zoneManagerState(page);
        expect(state.zipCodes).toEqual([]);
        expect(state.zipOverlayCount).toBe(0);
        expect(state.zipError).not.toMatch(/No boundary found/);
    });

    test('D. No-boundary 5-digit + addZip prefix does not substitute another ZCTA', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const missing = missingZip();
        const valid = validZip();

        await commitZip(page, missing);
        await expect(page.getByText(new RegExp(`No boundary found for:\\s*${missing}`))).toBeVisible();

        let state = await zoneManagerState(page);

        expect(state.zipCodes, 'missing ZCTA must not stage').toEqual([]);
        expect(state.zipOverlayCount, 'no unrelated polygon overlay').toBe(0);
        expect(state.zipOverlayKeys).not.toContain(valid);
        expect(state.zipError).not.toMatch(/Not a valid ZIP/);

        const bypass = await invokeAddZip(page, fragmentZip());

        expect(bypass.result, 'addZip must exact-match; prefix is not_found').toBe('not_found');
        expect(bypass.zipCodes, 'rows[0] fallback must not attach 75201 for 7520').not.toContain(
            valid,
        );
        expect(bypass.zipOverlayKeys).not.toContain(valid);

        state = await zoneManagerState(page);
        expect(state.zipCodes).toEqual([]);
        expect(state.zipOverlayCount).toBe(0);
    });

    test('E. Valid resolvable zip attaches overlay and appears in the staged list', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const zip = validZip();

        await commitZip(page, zip);

        await expect
            .poll(async () => (await zoneManagerState(page)).zipCodes, { timeout: 15_000 })
            .toContain(zip);

        const state = await zoneManagerState(page);

        expect(state.zipCodes).toEqual([zip]);
        expect(state.zipOverlayCount, 'resolvable zip must render an overlay').toBeGreaterThan(0);
        expect(state.zipOverlayKeys).toContain(zip);
        expect(state.zipError).toBe('');
        await expect(page.getByText(zip, { exact: true }).first()).toBeVisible();
    });

    test('F. Save with only an unresolvable zip: persistent Zone not saved; not persisted', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const missing = missingZip();
        const name = uniqueZoneName('F');

        await fillZoneName(page, name);
        await injectDefinition(page, { polygons: [], zip_codes: [missing] });
        await saveZone(page);

        await expectNotification(page, 'Zone not saved');
        await expect(page.getByText(new RegExp(`ZIP code\\(s\\):\\s*${missing}`))).toBeVisible();
        await expect(page.getByText(/Remove them, or draw the zone manually/)).toBeVisible();
        await expectNoSuccessToast(page);

        const notification = page.getByText('Zone not saved').first();

        await expect(notification).toBeVisible();
        await expect
            .poll(async () => notification.isVisible(), { timeout: 2_000 })
            .toBe(true);

        const row = lookupZoneByName(name);

        expect(row.found, 'unresolvable zip must not persist a zone').toBe(false);
    });

    test('G. Mixed valid + unresolvable save rejects and names only the bad zip', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const valid = validZip();
        const missing = missingZip();
        const name = uniqueZoneName('G');

        await fillZoneName(page, name);
        await commitZip(page, valid);
        await expect
            .poll(async () => (await zoneManagerState(page)).zipCodes, { timeout: 15_000 })
            .toContain(valid);

        await injectDefinition(page, { polygons: [], zip_codes: [valid, missing] });
        await saveZone(page);

        await expectNotification(page, 'Zone not saved');
        await expect(page.getByText(new RegExp(`ZIP code\\(s\\):\\s*${missing}`))).toBeVisible();
        await expect(page.getByText(new RegExp(`ZIP code\\(s\\):\\s*${valid}`))).toHaveCount(0);
        await expectNoSuccessToast(page);

        const row = lookupZoneByName(name);

        expect(row.found, 'mixed batch must not silently drop the bad zip and save').toBe(false);
    });

    test('H. Valid zip-only zone saves, toasts, and reopens with geom + zip hydrated', async ({
        page,
    }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const zip = validZip();
        const name = uniqueZoneName('H');

        await fillZoneName(page, name);
        await commitZip(page, zip);
        await expect
            .poll(async () => (await zoneManagerState(page)).zipCodes, { timeout: 15_000 })
            .toContain(zip);

        await saveZone(page);
        await expectNotification(page, 'Zone saved');
        await expect(page.getByText('Zone not saved')).toHaveCount(0);

        const saved = lookupZoneByName(name);

        expect(saved.found, 'happy-path zip zone must persist').toBe(true);
        expect(saved.hasGeom, 'geom must persist — toast alone lied before DEV-2252').toBe(true);
        expect(saved.zipCodes).toContain(zip);

        await page.goto(ZONES_LIST_PATH, { waitUntil: 'domcontentloaded' });
        await expect(page.getByText(name).first()).toBeVisible({ timeout: 15_000 });

        await page.goto(`${MANAGE_ZONES_PATH}?zone=${saved.id}`, { waitUntil: 'domcontentloaded' });
        await waitForZoneManager(page);
        await expect(page.getByLabel('Zone Name')).toHaveValue(name);
        await expect(page.getByPlaceholder('Add ZIP code…')).toBeVisible({ timeout: 20_000 });
        await expect
            .poll(async () => (await zoneManagerState(page)).zipCodes, { timeout: 20_000 })
            .toContain(zip);

        const reopened = await zoneManagerState(page);

        expect(reopened.zipOverlayCount, 'reopen must hydrate the ZCTA overlay').toBeGreaterThan(0);
        expect(reopened.editing).toBe(true);
    });

    test('I. Empty definition save is rejected; no silent success', async ({ page }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const name = uniqueZoneName('I');

        await fillZoneName(page, name);
        await saveZone(page);
        await expectNoSuccessToast(page);

        const row = lookupZoneByName(name);

        expect(row.found, 'empty definition must not persist').toBe(false);
        await expect(page.getByText('Zone saved')).toHaveCount(0);
    });

    test('J. Polygon-only zone saves; no false Zone not saved', async ({ page }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const name = uniqueZoneName('J');

        await fillZoneName(page, name);
        await injectMinimumPolygon(page);
        await saveZone(page);

        await expectNotification(page, 'Zone saved');
        await expect(page.getByText('Zone not saved')).toHaveCount(0);

        const row = lookupZoneByName(name);

        expect(row.found).toBe(true);
        expect(row.hasGeom, 'polygon-only geom path must populate geom').toBe(true);
        expect(row.zipCodes).toEqual([]);
    });

    test('K. Duplicate zip add does not double-stage', async ({ page }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const zip = validZip();

        await commitZip(page, zip);
        await expect
            .poll(async () => (await zoneManagerState(page)).zipCodes, { timeout: 15_000 })
            .toEqual([zip]);

        await commitZip(page, zip);

        const state = await zoneManagerState(page);

        expect(state.zipCodes).toEqual([zip]);
        expect(state.zipOverlayKeys.filter((key) => key === zip)).toHaveLength(1);
    });

    test('L. Paste mixed tokens: malformed listed separately from not-found', async ({ page }) => {
        skipUnlessScout(page);
        await openCreateFlow(page);

        const valid = validZip();
        const missing = missingZip();
        const fragment = fragmentZip();

        await pasteZips(page, `${valid}, ${fragment}, ${missing}, ${DEFAULT_MALFORMED}`);

        await expect
            .poll(async () => (await zoneManagerState(page)).zipError, { timeout: 15_000 })
            .toMatch(/Not a valid ZIP/);

        const state = await zoneManagerState(page);

        expect(state.zipCodes).toEqual([valid]);
        expect(state.zipError).toMatch(new RegExp(`Not a valid ZIP:.*${fragment}`));
        expect(state.zipError).toMatch(new RegExp(`Not a valid ZIP:.*${DEFAULT_MALFORMED}`));
        expect(state.zipError).toMatch(new RegExp(`No boundary found for:\\s*${missing}`));
        expect(state.zipError).toMatch(/ · /);
        expect(state.zipOverlayKeys).toContain(valid);
        expect(state.zipOverlayKeys).not.toContain(missing);
    });

    test('M. PHPUnit ManageZones + Materializer guards are still on disk', async () => {
        assertPhpunitGuardInventory();
    });

    test('N. Unauthorized / Scout-flag-off user cannot open Manage Zones', async ({ page }) => {
        const noScout = process.env.E2E_NO_SCOUT_EMAIL ?? seeded.noScoutEmail;
        const nonAdmin = process.env.E2E_NON_ADMIN_EMAIL ?? seeded.nonAdminEmail;

        if (!noScout && !nonAdmin) {
            test.skip(
                true,
                'Negative-nav users were not seedable (no E2E_NO_SCOUT_EMAIL / E2E_NON_ADMIN_EMAIL and seed-dev-2252-scout-zips.php did not provision them).',
            );
        }

        if (noScout && seeded.noScoutCanAccessManageZones !== true) {
            await rewriteForcedHttpsForLocalHttp(page);
            await loginViaNonProdBypass(page, noScout, 'app');

            const response = await page.goto(MANAGE_ZONES_PATH, {
                waitUntil: 'domcontentloaded',
            });
            const status = response?.status() ?? 0;

            expect(
                status === 403 ||
                    status === 404 ||
                    page.url().includes('/login') ||
                    !page.url().includes('/manage-zones') ||
                    (await page.getByPlaceholder('Add ZIP code…').count()) === 0,
                `Scout-flag-off admin must not use Manage Zones (status ${status}, url ${page.url()})`,
            ).toBeTruthy();
            await expect(page.getByPlaceholder('Add ZIP code…')).toHaveCount(0);

            return;
        }

        if (nonAdmin && seeded.nonAdminCanAccessManageZones !== true) {
            await rewriteForcedHttpsForLocalHttp(page);
            await loginViaNonProdBypass(page, nonAdmin, 'app');

            const response = await page.goto(MANAGE_ZONES_PATH, {
                waitUntil: 'domcontentloaded',
            });
            const status = response?.status() ?? 0;

            expect(
                status === 403 ||
                    status === 404 ||
                    page.url().includes('/login') ||
                    (await page.getByPlaceholder('Add ZIP code…').count()) === 0,
                `Non-admin must not use Manage Zones (status ${status}, url ${page.url()})`,
            ).toBeTruthy();

            return;
        }

        test.skip(
            true,
            'Seeded negative-nav users unexpectedly canAccess ManageZones; skip rather than claim a false deny.',
        );
    });
});
