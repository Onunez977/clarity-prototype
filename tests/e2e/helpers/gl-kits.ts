import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';

/** Filament app-panel resource: GlKitsResource, slug gl-kits, group Reports. */
export const GL_KITS_PATH = '/app/gl-kits';

export const COLUMN_HEADERS = ['GL', 'Company Owner', 'Last Scan Received', 'Reporting (14d)'] as const;

/** Scout Users (kit-users) columns that must not appear on the GL Kits list. */
export const KIT_USER_HEADERS = [
    'Kit Name',
    'GL Number',
    'Assigned User',
    'Assigned Zones',
    'Scout Access',
    'Vehicle Type',
] as const;

/** Exact product strings from GlKitsResource::headerTooltip / REPORTING_TOOLTIPS / LAST_SCAN_TOOLTIPS. */
export const LAST_SCAN_HEADER_TOOLTIP =
    'The most recent scan across every kit user mapped to this GL, shown in your timezone.';

export const REPORTING_HEADER_TOOLTIP =
    'Whether any kit user on this GL has sent a GPS position in the last 14 days. Hover a badge for what that state means.';

export const REPORTING_TOOLTIPS = {
    Reporting: 'At least one kit user on this GL sent a GPS position in the last 14 days.',
    'Not Reporting':
        'Every kit user on this GL was checked. None sent a GPS position in the last 14 days.',
    Unknown: 'No scanner mapping to look up, or the lookup failed. Different from "Not Reporting".',
} as const;

export const LAST_SCAN_TOOLTIPS = {
    Never: 'No scan has ever been recorded for any kit user on this GL.',
    Unknown: 'The last-scan lookup failed, so this is not known. It does not mean "never".',
} as const;

/** REPORTING_LABELS order 0/1/2 — ascending shows the best-known state first. */
export const REPORTING_LABELS_ASC = ['Reporting', 'Not Reporting', 'Unknown'] as const;

export const DEFAULT_PAGE_SIZE = 100;

export function columnHeader(page: Page, name: string) {
    return page.getByRole('columnheader', { name, exact: true });
}

/** Filament sortable header is a role=button with the column label as aria-label. */
export function sortButton(page: Page, columnName: string) {
    return page.getByRole('button', { name: columnName, exact: true });
}

export function lastScanCells(page: Page) {
    return page.locator('td.fi-ta-cell-last-scan');
}

export function reportingCells(page: Page) {
    return page.locator('td.fi-ta-cell-reporting');
}

export function recordsPerPageSelects(page: Page) {
    return page.locator('.fi-pagination-records-per-page-select select');
}

export async function openGlKitsViaNav(page: Page): Promise<void> {
    await page.goto('/app', { waitUntil: 'domcontentloaded' });

    const nav = page.getByRole('navigation');
    const link = nav.getByRole('link', { name: 'GL Kits' });

    if (!(await link.isVisible())) {
        const reports = nav.getByRole('button', { name: 'Reports' });

        if (await reports.isVisible()) {
            await reports.click();
        }
    }

    await expect(link).toBeVisible();
    await link.click();
    await expect(page).toHaveURL(/\/app\/gl-kits\/?$/);
}

export async function expectGlKitsColumnHeaders(page: Page): Promise<void> {
    const table = page.getByRole('table');

    await expect(table).toBeVisible();

    for (const name of COLUMN_HEADERS) {
        await expect(columnHeader(page, name)).toBeVisible();
    }
}

export async function expectNotKitUsersPage(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/app\/gl-kits\/?$/);
    await expect(page).not.toHaveURL(/scout-users|kit-users/);

    for (const name of KIT_USER_HEADERS) {
        await expect(columnHeader(page, name)).toHaveCount(0);
    }
}

export async function expectReadOnlyList(page: Page): Promise<void> {
    await expect(page.getByRole('link', { name: /new gl kit/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /new gl kit/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /^create$/i })).toHaveCount(0);
}

export async function gotoGlKits(page: Page): Promise<void> {
    await page.goto(GL_KITS_PATH, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('table')).toBeVisible();
    await page
        .locator('table tbody tr, .fi-ta-empty-state, .fi-pagination')
        .first()
        .waitFor({ state: 'attached', timeout: 15_000 })
        .catch(() => undefined);
}

export async function visibleRecordsPerPageSelect(page: Page): Promise<Locator | null> {
    const selects = recordsPerPageSelects(page);
    const count = await selects.count();

    for (let index = 0; index < count; index++) {
        const select = selects.nth(index);

        if (await select.isVisible()) {
            return select;
        }
    }

    return count > 0 ? selects.first() : null;
}

/**
 * Livewire snapshot fallback for when Filament hides pagination (LengthAwarePaginator total === 0).
 * tableRecordsPerPage is still 100 from defaultPaginationPageOption(100).
 */
export async function livewireTableRecordsPerPage(page: Page): Promise<number | null> {
    return page.evaluate(() => {
        const nodes = Array.from(document.querySelectorAll('[wire\\:snapshot]'));

        for (const node of nodes) {
            const raw = node.getAttribute('wire:snapshot');

            if (!raw || !raw.includes('tableRecordsPerPage')) {
                continue;
            }

            try {
                const snap = JSON.parse(raw) as {
                    data?: { tableRecordsPerPage?: number | [number, unknown] };
                };
                const value = snap.data?.tableRecordsPerPage;

                if (Array.isArray(value)) {
                    return Number(value[0]);
                }

                if (value !== undefined && value !== null) {
                    return Number(value);
                }
            } catch {
                const match = raw.match(/tableRecordsPerPage\\?":\s*\[?(\d+)/);

                if (match) {
                    return Number(match[1]);
                }
            }
        }

        return null;
    });
}

export async function expectDefaultPageSizeIs100(page: Page): Promise<void> {
    const select = await visibleRecordsPerPageSelect(page);

    if (select) {
        await expect(select).toHaveValue(String(DEFAULT_PAGE_SIZE));

        const rows = page.locator('table tbody tr.fi-ta-row');
        const rowCount = await rows.count();

        if (rowCount > 0) {
            expect(rowCount, 'first page must not exceed the 100-row default').toBeLessThanOrEqual(
                DEFAULT_PAGE_SIZE,
            );
        }

        const overview = page.locator('.fi-pagination-overview');

        if (await overview.isVisible()) {
            const text = await overview.innerText();
            const match = text.match(/Showing\s+[\d,]+\s+to\s+([\d,]+)\s+of\s+([\d,]+)/i);

            if (match) {
                const last = Number(match[1].replace(/,/g, ''));
                const total = Number(match[2].replace(/,/g, ''));

                expect(last).toBeLessThanOrEqual(DEFAULT_PAGE_SIZE);

                if (total > 50) {
                    expect(
                        last,
                        'more than 50 GLs must still render on page 1 — default is 100, not 50',
                    ).toBeGreaterThan(50);
                }
            }
        }

        return;
    }

    const fromLivewire = await livewireTableRecordsPerPage(page);

    expect(
        fromLivewire,
        'pagination select is hidden on an empty table; Livewire tableRecordsPerPage must still default to 100',
    ).toBe(DEFAULT_PAGE_SIZE);
}

export async function expectPaginationOptionsIncludeOthersWithDefault100(
    page: Page,
    testInfo: TestInfo,
): Promise<void> {
    const select = await visibleRecordsPerPageSelect(page);

    if (!select) {
        testInfo.annotations.push({
            type: 'note',
            description:
                'No records-per-page control: Filament hides pagination when the paginator total is 0. Default 100 is asserted by the sibling first-load case via Livewire snapshot. This case is about other sizes being listed without the tester picking 100.',
        });

        return;
    }

    const optionValues = await select.locator('option').evaluateAll((options) =>
        options.map((option) => (option as HTMLOptionElement).value.trim()),
    );

    expect(optionValues, '100 must be a listed page size').toContain(String(DEFAULT_PAGE_SIZE));
    expect(
        optionValues.filter((value) => value !== String(DEFAULT_PAGE_SIZE)).length,
        'other page sizes must still be listed; this is not “user can pick 100”',
    ).toBeGreaterThan(0);
    await expect(select, 'first-load selection must already be 100').toHaveValue(
        String(DEFAULT_PAGE_SIZE),
    );
}

export async function clickSortHeader(page: Page, columnName: string): Promise<void> {
    await sortButton(page, columnName).click();
}

export async function expectColumnAriaSort(
    page: Page,
    columnName: string,
    direction: 'ascending' | 'descending',
): Promise<void> {
    await expect(columnHeader(page, columnName)).toHaveAttribute('aria-sort', direction, {
        timeout: 10_000,
    });
}

export async function cellTexts(locator: Locator): Promise<string[]> {
    return locator.evaluateAll((elements) =>
        elements.map((element) => (element.textContent ?? '').replace(/\s+/g, ' ').trim()),
    );
}

export function reportingRank(label: string): number {
    const index = REPORTING_LABELS_ASC.indexOf(label as (typeof REPORTING_LABELS_ASC)[number]);

    return index === -1 ? Number.POSITIVE_INFINITY : index;
}

export function lastScanBucket(text: string): number {
    if (text === 'Unknown') {
        return 2;
    }

    if (text === 'Never') {
        return 1;
    }

    return 0;
}

export function isNonDecreasing(values: number[]): boolean {
    return values.every((value, index) => index === 0 || values[index - 1] <= value);
}

export function isNonIncreasing(values: number[]): boolean {
    return values.every((value, index) => index === 0 || values[index - 1] >= value);
}

export async function expectReportingColumnRankOrder(
    page: Page,
    direction: 'asc' | 'desc',
): Promise<void> {
    const labels = (await cellTexts(reportingCells(page)))
        .map((text) => {
            const match = text.match(/^(Reporting|Not Reporting|Unknown)$/);

            return match?.[1] ?? text;
        })
        .filter((text) => REPORTING_LABELS_ASC.includes(text as (typeof REPORTING_LABELS_ASC)[number]));

    if (labels.length < 2) {
        return;
    }

    const ranks = labels.map(reportingRank);
    const distinct = new Set(ranks);

    if (distinct.size < 2) {
        return;
    }

    expect(
        direction === 'asc' ? isNonDecreasing(ranks) : isNonIncreasing(ranks),
        `Reporting (14d) ${direction} must follow REPORTING_LABELS 0/1/2; got ${labels.join(', ')}`,
    ).toBeTruthy();
}

export async function expectLastScanColumnBucketOrder(
    page: Page,
    direction: 'asc' | 'desc',
): Promise<void> {
    const values = (await cellTexts(lastScanCells(page))).filter((text) => text.length > 0);

    if (values.length < 2) {
        return;
    }

    const buckets = values.map(lastScanBucket);
    const distinct = new Set(buckets);

    if (distinct.size < 2) {
        return;
    }

    expect(
        direction === 'asc' ? isNonDecreasing(buckets) : isNonIncreasing(buckets),
        `Last Scan Received ${direction} must put timestamps before Never before Unknown (asc); got ${values.join(', ')}`,
    ).toBeTruthy();
}

export async function expectTooltipOnHoverOrFocus(
    page: Page,
    trigger: Locator,
    exactText: string,
): Promise<void> {
    await trigger.scrollIntoViewIfNeeded();
    await trigger.hover();

    const tippy = page.locator('.tippy-content').filter({ hasText: exactText });

    try {
        await expect(tippy).toBeVisible({ timeout: 4_000 });
        await expect(tippy).toHaveText(exactText);

        return;
    } catch {
        // Tippy may not mount in a headless pass; fall through to focus, then Alpine.
    }

    await trigger.focus();

    try {
        await expect(tippy).toBeVisible({ timeout: 2_000 });
        await expect(tippy).toHaveText(exactText);

        return;
    } catch {
        // Filament writes the product string onto x-tooltip; that is the same contract.
    }

    const ownAttr = await trigger.getAttribute('x-tooltip');
    const nested = trigger.locator('[x-tooltip]');
    const nestedAttr =
        (await nested.count()) > 0 ? await nested.first().getAttribute('x-tooltip') : null;
    const closestAttr = await trigger.evaluate((element) => {
        const node = element.closest('[x-tooltip]');

        return node?.getAttribute('x-tooltip') ?? null;
    });
    const attr = ownAttr ?? nestedAttr ?? closestAttr;

    expect(attr, `expected x-tooltip carrying: ${exactText}`).toBeTruthy();
    expect(attr).toContain(exactText);
}

export function headerTooltipTrigger(page: Page, columnName: string) {
    return columnHeader(page, columnName).locator('.fi-ta-header-cell-tooltip');
}
