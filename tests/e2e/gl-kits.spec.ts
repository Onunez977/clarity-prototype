import { expect, test } from '@playwright/test';
import {
    assertNotProduction,
    loginAsAdmin,
    loginAsNonAdmin,
    nonAdminUserId,
} from './helpers/auth';
import {
    COLUMN_HEADERS,
    clickSortHeader,
    expectColumnAriaSort,
    expectDefaultPageSizeIs100,
    expectGlKitsColumnHeaders,
    expectLastScanColumnBucketOrder,
    expectNotKitUsersPage,
    expectPaginationOptionsIncludeOthersWithDefault100,
    expectReadOnlyList,
    expectReportingColumnRankOrder,
    expectTooltipOnHoverOrFocus,
    GL_KITS_PATH,
    gotoGlKits,
    headerTooltipTrigger,
    LAST_SCAN_HEADER_TOOLTIP,
    LAST_SCAN_TOOLTIPS,
    lastScanCells,
    openGlKitsViaNav,
    REPORTING_HEADER_TOOLTIP,
    REPORTING_TOOLTIPS,
    reportingCells,
} from './helpers/gl-kits';

/**
 * DEV-2486 — Clarity (Filament app panel) read-only GL Kits list.
 *
 * UI contract (GlKitsResource + ListGlKits):
 * - panel id `app`, path `app` → `/app/gl-kits`
 * - nav: Reports / GL Kits / heroicon-o-cpu-chip / sort 3
 * - columns: GL (sortable/searchable), Company Owner, Last Scan Received, Reporting (14d)
 * - one row per GL, not per kit (DEV-2486); rows resolved from ClickHouse, 100/page default
 * - ADMIN only (`RoleEnum::ADMIN`); canCreate/canEdit/canDelete false; no header actions
 * - kit scope (feeds the ClickHouse reads): org_id = auth user org,
 *   kit_type = KitType::GENETEC (1), excludingDeleted(), owned-only
 *
 * ClickHouse-backed row states are pinned in PHPUnit:
 * - tests/Filament/Resources/GlKitsResourceTest.php
 * - tests/Filament/Resources/GlKitsResourceAccessTest.php
 * Playwright asserts the observable DOM; CH-seeded cases skip unless the isolated
 * cloud sets the matching E2E_* flag after factory/artisan seed.
 */
test.describe('DEV-2486 GL Kits list', () => {
    test.beforeAll(() => {
        assertNotProduction(process.env.BASE_URL ?? process.env.APP_URL);
    });

    test.describe('admin (RoleEnum::ADMIN)', () => {
        test.beforeEach(async ({ page }) => {
            await loginAsAdmin(page);
        });

        test('AC1/AC3: Reports → GL Kits opens the org-scoped kits list at /app/gl-kits', async ({
            page,
        }) => {
            await openGlKitsViaNav(page);
            await expect(page).toHaveURL(/\/app\/gl-kits\/?$/);
            await expect(page.getByRole('table')).toBeVisible();
        });

        test('AC2: column headers are GL, Company Owner, Last Scan Received, Reporting (14d)', async ({
            page,
        }) => {
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expectGlKitsColumnHeaders(page);

            expect(COLUMN_HEADERS).toEqual([
                'GL',
                'Company Owner',
                'Last Scan Received',
                'Reporting (14d)',
            ]);
        });

        test('AC1: page is a GL list, not a kit-users (Scout Users) list', async ({ page }) => {
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expectNotKitUsersPage(page);
            await expect(page.getByRole('columnheader', { name: 'GL', exact: true })).toBeVisible();
            // DEV-2486: the Kit column was removed — one row per device, not per person.
            await expect(page.getByRole('columnheader', { name: 'Kit', exact: true })).toHaveCount(0);
        });

        test('read-only: no create / header actions', async ({ page }) => {
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expectReadOnlyList(page);
        });

        test('AC3: Company Owner is the viewing org (constant column, owned-only)', async ({
            page,
        }) => {
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expect(
                page.getByRole('columnheader', { name: 'Company Owner', exact: true }),
            ).toBeVisible();

            // When rows exist, every Company Owner cell is the same org name
            // (owned-only — Shared pivot kits never appear). PHPUnit pins the
            // other-org exclusion in GlKitsResourceAccessTest.
            const rows = page.locator('table tbody tr');
            const rowCount = await rows.count();

            if (rowCount === 0) {
                test.info().annotations.push({
                    type: 'note',
                    description:
                        'Empty table: org scoping of rows is pinned by GlKitsResourceTest::test_another_orgs_gl_never_appears, and the kit-query boundary it derives from by GlKitsResourceAccessTest::test_the_kit_query_excludes_another_orgs_kits.',
                });

                return;
            }

            const ownerTexts = await rows.evaluateAll((elements) =>
                elements
                    .map((row) => {
                        const cells = Array.from(row.querySelectorAll('td'));

                        return cells[1]?.textContent?.trim() ?? '';
                    })
                    .filter((text) => text.length > 0),
            );

            const unique = [...new Set(ownerTexts)];

            expect(unique.length, 'Company Owner must be a single viewing-org name').toBe(1);
        });

        test('AC4/AC5/AC6: Reporting badge is three-state; Last Scan has Never / timestamp / Unknown', async ({
            page,
        }) => {
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expect(
                page.getByRole('columnheader', { name: 'Reporting (14d)', exact: true }),
            ).toBeVisible();
            await expect(
                page.getByRole('columnheader', { name: 'Last Scan Received', exact: true }),
            ).toBeVisible();

            const reporting = page.locator('.fi-badge').filter({
                hasText: /^(Reporting|Not Reporting|Unknown)$/,
            });
            const lastScanPlaceholders = page.getByText(/^(Never|Unknown)$/);

            if ((await reporting.count()) === 0 && (await lastScanPlaceholders.count()) === 0) {
                test.info().annotations.push({
                    type: 'note',
                    description:
                        'No row-state badges on this seed. PHPUnit pins: Never (no scan rows), Unknown reporting (no kit_mapping), a reporting read failure rendering Unknown, and zero rows on a GL-discovery failure (DEV-2486).',
                });

                return;
            }

            const badgeTexts = await reporting.allTextContents();
            const allowed = new Set(['Reporting', 'Not Reporting', 'Unknown']);

            for (const text of badgeTexts.map((value) => value.trim())) {
                expect(allowed.has(text), `unexpected reporting badge: ${text}`).toBeTruthy();
            }

            // AC-6: Unknown must stay distinct from Not Reporting (warning + question-mark).
            const unknownBadge = page.locator('.fi-badge').filter({ hasText: /^Unknown$/ });

            if ((await unknownBadge.count()) > 0) {
                await expect(unknownBadge.first()).toBeVisible();
                await expect(unknownBadge.first()).toHaveClass(/warning|fi-color-warning/);
            }
        });

        test('Default pagination page option is 100 on first load', async ({ page }) => {
            await gotoGlKits(page);
            await expectDefaultPageSizeIs100(page);
        });

        test('Pagination options still include other sizes; default remains 100 without selecting it', async ({
            page,
        }, testInfo) => {
            await gotoGlKits(page);
            await expectPaginationOptionsIncludeOthersWithDefault100(page, testInfo);
        });

        test('Click Last Scan Received header sorts ascending', async ({ page }) => {
            await gotoGlKits(page);
            await clickSortHeader(page, 'Last Scan Received');
            await expectColumnAriaSort(page, 'Last Scan Received', 'ascending');
            await expectLastScanColumnBucketOrder(page, 'asc');
        });

        test('Second click on Last Scan Received sorts descending', async ({ page }) => {
            await gotoGlKits(page);
            await clickSortHeader(page, 'Last Scan Received');
            await expectColumnAriaSort(page, 'Last Scan Received', 'ascending');
            await clickSortHeader(page, 'Last Scan Received');
            await expectColumnAriaSort(page, 'Last Scan Received', 'descending');
            await expectLastScanColumnBucketOrder(page, 'desc');

            const header = page.getByRole('columnheader', { name: 'Last Scan Received', exact: true });

            await expect(header).not.toHaveAttribute('aria-sort', 'ascending');
        });

        test('Click Reporting (14d) header sorts ascending (Reporting before Not Reporting before Unknown)', async ({
            page,
        }) => {
            await gotoGlKits(page);
            await clickSortHeader(page, 'Reporting (14d)');
            await expectColumnAriaSort(page, 'Reporting (14d)', 'ascending');
            await expectReportingColumnRankOrder(page, 'asc');
        });

        test('Reporting (14d) descending is distinct from ascending', async ({ page }) => {
            await gotoGlKits(page);
            await clickSortHeader(page, 'Reporting (14d)');
            await expectColumnAriaSort(page, 'Reporting (14d)', 'ascending');
            await clickSortHeader(page, 'Reporting (14d)');
            await expectColumnAriaSort(page, 'Reporting (14d)', 'descending');
            await expectReportingColumnRankOrder(page, 'desc');

            const header = page.getByRole('columnheader', { name: 'Reporting (14d)', exact: true });

            await expect(header).not.toHaveAttribute('aria-sort', 'ascending');
        });

        test('Last Scan Received headerTooltip is present on hover/focus', async ({ page }) => {
            await gotoGlKits(page);

            const trigger = headerTooltipTrigger(page, 'Last Scan Received');

            await expect(trigger).toBeVisible();
            await expectTooltipOnHoverOrFocus(page, trigger, LAST_SCAN_HEADER_TOOLTIP);
        });

        test('Reporting (14d) headerTooltip is present on hover/focus', async ({ page }) => {
            await gotoGlKits(page);

            const trigger = headerTooltipTrigger(page, 'Reporting (14d)');

            await expect(trigger).toBeVisible();
            await expectTooltipOnHoverOrFocus(page, trigger, REPORTING_HEADER_TOOLTIP);
        });

        test('Reporting badge cell tooltip matches REPORTING_TOOLTIPS for the visible state', async ({
            page,
        }, testInfo) => {
            await gotoGlKits(page);

            const badges = reportingCells(page).locator('.fi-badge').filter({
                hasText: /^(Reporting|Not Reporting|Unknown)$/,
            });

            if ((await badges.count()) === 0) {
                testInfo.annotations.push({
                    type: 'note',
                    description:
                        'No Reporting badge on this seed. Cell tooltip strings are pinned by GlKitsResourceTest::test_each_reporting_state_carries_an_explanatory_tooltip. Do not delete this case.',
                });

                return;
            }

            const badge = badges.first();
            const state = ((await badge.innerText()) ?? '').replace(/\s+/g, ' ').trim();
            const expected =
                REPORTING_TOOLTIPS[state as keyof typeof REPORTING_TOOLTIPS];

            expect(expected, `unexpected reporting badge: ${state}`).toBeTruthy();
            await expectTooltipOnHoverOrFocus(page, badge, expected);
        });

        test('Never or Unknown last-scan placeholder cell tooltip matches LAST_SCAN_TOOLTIPS', async ({
            page,
        }, testInfo) => {
            await gotoGlKits(page);

            const placeholders = lastScanCells(page).filter({ hasText: /^(Never|Unknown)$/ });

            if ((await placeholders.count()) === 0) {
                testInfo.annotations.push({
                    type: 'note',
                    description:
                        'No Never/Unknown last-scan placeholder on this seed. Cell tooltip strings are pinned by GlKitsResourceTest::test_last_scan_sentinels_carry_tooltips_but_real_timestamps_do_not. Do not delete this case.',
                });

                return;
            }

            const cell = placeholders.first();
            const state = ((await cell.innerText()) ?? '').replace(/\s+/g, ' ').trim();
            const expected = LAST_SCAN_TOOLTIPS[state as keyof typeof LAST_SCAN_TOOLTIPS];
            const trigger =
                (await cell.locator('[x-tooltip]').count()) > 0
                    ? cell.locator('[x-tooltip]').first()
                    : cell;

            expect(expected, `unexpected last-scan placeholder: ${state}`).toBeTruthy();
            await expectTooltipOnHoverOrFocus(page, trigger, expected);
        });
    });

    test.describe('non-admin (no RoleEnum::ADMIN)', () => {
        test.skip(
            !nonAdminUserId() && !process.env.E2E_NON_ADMIN_EMAIL,
            'Set E2E_NON_ADMIN_USER_ID to a factory user created like ResourceTestCase createUser(role: []) — no RoleEnum::ADMIN. PHPUnit pins 403 in GlKitsResourceAccessTest::test_cannot_render_without_the_admin_role.',
        );

        test('AC3 access: non-admin cannot open /app/gl-kits (403)', async ({ page }) => {
            await loginAsNonAdmin(page);

            const response = await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });

            expect(response, 'expected a response from /app/gl-kits').not.toBeNull();
            expect(response?.status()).toBe(403);
        });

        test('AC3 access: GL Kits is hidden from nav for non-admin', async ({ page }) => {
            await loginAsNonAdmin(page);
            await page.goto('/app', { waitUntil: 'domcontentloaded' });

            await expect(page.getByRole('navigation').getByRole('link', { name: 'GL Kits' })).toHaveCount(
                0,
            );
        });
    });

    test.describe('behind-the-scenes contracts (skip unless isolated-cloud seed)', () => {
        test('last scan Never when kit has no scans; Reporting = Not Reporting', async ({ page }) => {
            test.skip(
                process.env.E2E_SEED_NEVER_KIT !== '1',
                'Needs a kit_type=1 row with no zod.kit_last_scan / no sc_positions. Also needs an aggregate_kit_camera_name row: since DEV-2486 a GL only exists once a scan recorded that camera_name. Pinned by GlKitsResourceTest::test_a_gl_with_no_scans_renders_never.',
            );

            await loginAsAdmin(page);
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expect(page.getByText('Never').first()).toBeVisible();
            await expect(page.locator('.fi-badge').filter({ hasText: 'Not Reporting' }).first()).toBeVisible();
        });

        test('empty kit_mapping renders Reporting Unknown, not Not Reporting', async ({ page }) => {
            test.skip(
                process.env.E2E_SEED_EMPTY_MAPPING !== '1',
                'Needs a kit_type=1 row with kit_mapping="". Pinned by GlKitsResourceTest::test_a_gl_whose_only_kit_has_no_mapping_renders_unknown.',
            );

            await loginAsAdmin(page);
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.fi-badge').filter({ hasText: /^Unknown$/ }).first()).toBeVisible();
        });

        test('CH outage renders an empty table (row identity comes from ClickHouse)', async ({
            page,
        }) => {
            test.skip(
                process.env.E2E_CH_OUTAGE !== '1',
                'Playwright cannot take ClickHouse down. Since DEV-2486 a GL-discovery failure renders ZERO ROWS rather than Unknown cells (row identity comes from ClickHouse), so this asserts an empty table. Pinned by GlKitsResourceTest::test_a_gl_discovery_failure_renders_zero_rows_and_is_reported and ::test_a_reporting_read_failure_renders_unknown_not_not_reporting.',
            );

            await loginAsAdmin(page);
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });

            // DEV-2486's accepted trade-off: rows come from ClickHouse, so a failed GL lookup
            // yields no rows at all rather than Unknown cells. The page must still render.
            await expect(page.getByRole('table')).toBeVisible();
            await expect(page.locator('table tbody tr')).toHaveCount(0);
        });

        test('stale kit_last_scan under a former org renders Never (org_id predicate)', async ({
            page,
        }) => {
            test.skip(
                process.env.E2E_SEED_STALE_LAST_SCAN !== '1',
                'Needs a kit_last_scan row filed under a different org_id. Pinned by GlKitsResourceTest::test_last_scan_row_under_a_different_org_does_not_surface.',
            );

            await loginAsAdmin(page);
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
            await expect(page.getByText('Never').first()).toBeVisible();
        });

        test('kit_type=2 and another org GL kits and soft-deleted kits do not appear', async ({
            page,
        }) => {
            test.skip(
                process.env.E2E_SEED_EXCLUSIONS !== '1',
                'Needs named GLs (camera_name) for type=2 / other-org / soft-deleted. Pinned by GlKitsResourceTest (test_only_gl_kit_type_kits_contribute_rows, test_another_orgs_gl_never_appears, test_a_soft_deleted_kits_gl_does_not_appear) — these moved out of the ClickHouse-free class in DEV-2486, because row identity now comes from ClickHouse.',
            );

            await loginAsAdmin(page);
            await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });

            // Must be GL/camera names, not kit names: the Kit column was removed in DEV-2486, so
            // asserting kit names are absent passes for any input and pins nothing.
            const hidden = (process.env.E2E_HIDDEN_GL_NAMES ?? '')
                .split(',')
                .map((name) => name.trim())
                .filter(Boolean);

            expect(
                hidden.length,
                'Set E2E_HIDDEN_GL_NAMES to the camera_name values that must NOT appear (from kit_type=2 / other-org / soft-deleted kits). E2E_HIDDEN_KIT_NAMES no longer works — kit names are not rendered.',
            ).toBeGreaterThan(0);

            for (const name of hidden) {
                await expect(page.getByRole('cell', { name, exact: true })).toHaveCount(0);
            }
        });
    });
});
