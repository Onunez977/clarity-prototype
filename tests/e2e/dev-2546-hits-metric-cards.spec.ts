import { expect, test, type Page } from '@playwright/test';
import { loginViaNonProdBypass, rewriteForcedHttpsForLocalHttp } from './helpers/auth';
import {
    cardByKey,
    cardsOverflow,
    chooseSelect,
    chooseToggle,
    clearHitMetricFailure,
    clearLoginRateLimit,
    columnRowCount,
    expectCounted,
    expectZero,
    liveCardDescription,
    liveCardValue,
    applyFilters,
    markHitMetricFailure,
    readCards,
    resetFilters,
    seedDev2546,
    sidebar,
    type Dev2546Seed,
} from './helpers/dev-2546';

/**
 * DEV-2546 — Hits Page filter-reactive metric cards (Playwright A–L).
 *
 * Product under test (do not edit from this spec):
 * - app/Filament/Concerns/HasHitMetricCards.php
 * - app/Filament/Widgets/Hits/HitMetricCards.php
 * - app/Filament/Resources/HitsResource/Pages/ListHits.php
 *
 * Isolated cloud / local only. Never production. Never a VPN PR preview.
 */

test.use({
    video: 'on',
    viewport: { width: 1280, height: 900 },
    storageState: { cookies: [], origins: [] },
});

test.describe.configure({ mode: 'serial', timeout: 120_000 });

let seeded: Dev2546Seed;

test.beforeAll(() => {
    seeded = seedDev2546();
    process.env.E2E_ADMIN_EMAIL = seeded.adminEmail;
    process.env.E2E_ADMIN_USER_ID = String(seeded.adminUserId);
});

test.afterAll(() => {
    clearHitMetricFailure();
});

async function openHits(page: Page): Promise<void> {
    clearLoginRateLimit();
    await rewriteForcedHttpsForLocalHttp(page);
    await loginViaNonProdBypass(page, seeded.adminEmail);
    const response = await page.goto('/app/hits', { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 200).toBeLessThan(500);
    await expect(page.locator('[data-hit-metric-card="live-hits"]')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('Count unavailable')).toHaveCount(0);
}

test('A populated default filters render four cards', async ({ page }) => {
    await openHits(page);
    await expect(page.getByText('Type: Live').first()).toBeVisible({ timeout: 20_000 });
    const cards = await readCards(page);

    expect(cards.map((card) => card.label)).toEqual([
        'Live Hits',
        'Direct Historical Hits',
        'Staging Live Hits',
        'Staging Historical Hits',
    ]);

    expectCounted(cardByKey(cards, 'live-hits'), seeded.today.L, 'live');
    expectCounted(cardByKey(cards, 'historical-hits'), seeded.today.H, 'historical');
    expectCounted(cardByKey(cards, 'staging-live-hits'), seeded.today.S, 'staging live');
    expectCounted(cardByKey(cards, 'staging-historical-hits'), seeded.today.Z, 'staging historical');
    await expect(page.getByText('Type: Live').first()).toBeVisible();
    await page.screenshot({ path: '/opt/cursor/artifacts/qa-2546-a-four-cards.png', fullPage: false });
});

test('B Hit Date Last 7d Apply refreshes every card', async ({ page }) => {
    await openHits(page);
    const before = await readCards(page);
    const beforeLive = cardByKey(before, 'live-hits').description;

    await chooseToggle(page, 'Filter by Hit Date', 'Last 7d');
    await applyFilters(page);

    await expect(liveCardDescription(page)).toHaveText(`${seeded.last7d.L.total.toLocaleString('en-US')} total hits`);
    const after = await readCards(page);
    expectCounted(cardByKey(after, 'live-hits'), seeded.last7d.L, 'live last 7d');
    expectCounted(cardByKey(after, 'historical-hits'), seeded.last7d.H, 'historical last 7d');
    expectCounted(cardByKey(after, 'staging-live-hits'), seeded.last7d.S, 'staging live last 7d');
    expectCounted(cardByKey(after, 'staging-historical-hits'), seeded.last7d.Z, 'staging historical last 7d');
    expect(cardByKey(after, 'live-hits').description).not.toBe(beforeLive);
    await page.getByRole('button', { name: 'Hit Created' }).click();
    await expect(page.getByText(seeded.plates.old).first()).toBeVisible();
    await page.screenshot({ path: '/opt/cursor/artifacts/qa-2546-b-after-apply.png', fullPage: false });
});

test('C VIN HasClickableFilter chip updates cards without Search', async ({ page }) => {
    await openHits(page);
    const vin = seeded.vins.historical;
    const chip = page.locator(`[wire\\:click*="${vin}"]`);
    await expect(chip).toBeVisible({ timeout: 20_000 });

    const pending = page.waitForResponse(
        (response) => response.request().method() === 'POST' && response.url().includes('livewire'),
        { timeout: 20_000 },
    );
    await chip.click();
    await pending.catch(() => undefined);
    await expect(liveCardValue(page)).toHaveText(/^\s*0\s*$/);

    const cards = await readCards(page);
    expectCounted(cardByKey(cards, 'historical-hits'), { unique: 1, total: 1 }, 'historical chip');
    expectZero(cardByKey(cards, 'live-hits'));
    expectZero(cardByKey(cards, 'staging-live-hits'));
    expectZero(cardByKey(cards, 'staging-historical-hits'));
    await expect(page.getByText(seeded.plates.historical).first()).toBeVisible();
    await expect(page.getByText(seeded.plates.live)).toHaveCount(0);
});

test('D reset returns cards to the broader default window', async ({ page }) => {
    await openHits(page);
    await sidebar(page).getByLabel('Search by Vehicle Plate').fill(seeded.plates.historical);
    await applyFilters(page);
    await expect(liveCardValue(page)).toHaveText(/^\s*0\s*$/);

    const narrowed = await readCards(page);
    expectZero(cardByKey(narrowed, 'live-hits'));
    expectCounted(cardByKey(narrowed, 'historical-hits'), { unique: 1, total: 1 }, 'narrow historical');

    await resetFilters(page);
    await applyFilters(page);
    await expect(liveCardDescription(page)).toHaveText(`${seeded.today.L.total.toLocaleString('en-US')} total hits`);

    const restored = await readCards(page);
    expectCounted(cardByKey(restored, 'live-hits'), seeded.today.L, 'restored live');
    expectCounted(cardByKey(restored, 'historical-hits'), seeded.today.H, 'restored historical');
    expectCounted(cardByKey(restored, 'staging-live-hits'), seeded.today.S, 'restored staging live');
    expectCounted(cardByKey(restored, 'staging-historical-hits'), seeded.today.Z, 'restored staging historical');
    await expect(page.getByText('Type: Live').first()).toBeVisible();
});

test('E empty plate filter shows zero cards and an intact layout', async ({ page }) => {
    await openHits(page);
    await sidebar(page).getByLabel('Search by Vehicle Plate').fill('ZZZNOHIT');
    await applyFilters(page);
    await expect(liveCardDescription(page)).toHaveText('0 total hits');

    const cards = await readCards(page);
    for (const card of cards) {
        expectZero(card);
    }

    await expect(page.getByText('Type: Live')).toHaveCount(0);
    await expect(page.getByText('Type: Historical')).toHaveCount(0);
    await expect(page.locator('[data-hit-metric-card]')).toHaveCount(4);
    await page.screenshot({ path: '/opt/cursor/artifacts/qa-2546-e-empty.png', fullPage: false });
});

test('F each card counts only its hit type', async ({ page }) => {
    await openHits(page);
    const cards = await readCards(page);

    expectCounted(cardByKey(cards, 'live-hits'), seeded.today.L, 'live family');
    expectCounted(cardByKey(cards, 'historical-hits'), seeded.today.H, 'historical family');
    expectCounted(cardByKey(cards, 'staging-live-hits'), seeded.today.S, 'staging live family');
    expectCounted(cardByKey(cards, 'staging-historical-hits'), seeded.today.Z, 'staging historical family');

    await expect(page.getByText('Type: Live').first()).toBeVisible();
    await expect(page.getByText('Type: Historical').first()).toBeVisible();
    await expect(page.getByText('Type: Staging Live').first()).toBeVisible();
    await expect(page.getByText('Type: Staging Historical').first()).toBeVisible();
    expect(seeded.today.L.unique).toBeLessThan(seeded.today.L.total);
    await page.screenshot({ path: '/opt/cursor/artifacts/qa-2546-f-mix.png', fullPage: false });
});

test('G cards reflow from 1 to 2 to 4 columns', async ({ page }) => {
    await openHits(page);

    await page.setViewportSize({ width: 390, height: 900 });
    await expect(page.locator('[data-hit-metric-card="live-hits"]')).toBeVisible();
    expect(await columnRowCount(page)).toBe(4);
    expect(await cardsOverflow(page)).toBe(false);

    await page.setViewportSize({ width: 800, height: 900 });
    expect(await columnRowCount(page)).toBe(2);
    expect(await cardsOverflow(page)).toBe(false);

    await page.setViewportSize({ width: 1280, height: 900 });
    expect(await columnRowCount(page)).toBe(1);
    expect(await cardsOverflow(page)).toBe(false);
    await expect(page.getByText('Live Hits', { exact: true })).toBeVisible();
    await expect(page.getByText('Staging Historical Hits', { exact: true })).toBeVisible();
});

test('H sort and pagination leave card counts unchanged', async ({ page }) => {
    await openHits(page);
    const before = JSON.stringify(await readCards(page));

    await page.getByRole('button', { name: 'Hit Created' }).click();
    await expect(page.locator('[data-hit-metric-card="live-hits"]')).toBeVisible();
    expect(JSON.stringify(await readCards(page))).toBe(before);

    const next = page.getByRole('button', { name: 'Next' });
    await expect(next).toBeVisible();
    await next.click();
    await expect(page.locator('table')).toBeVisible();
    expect(JSON.stringify(await readCards(page))).toBe(before);
});

test('I failure marker shows Count unavailable and the table stays up', async ({ page }) => {
    await openHits(page);
    await expect(page.getByText('Type: Live').first()).toBeVisible();

    const marked = markHitMetricFailure();
    expect(marked).toBeGreaterThan(0);

    const response = await page.reload({ waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 200).toBeLessThan(500);
    await expect(liveCardValue(page)).toHaveText('—');

    const cards = await readCards(page);
    for (const card of cards) {
        expect(card.value).toBe('—');
        expect(card.description).toBe('Count unavailable');
    }

    await expect(page.getByText('Type: Live').first()).toBeVisible();
    await page.screenshot({ path: '/opt/cursor/artifacts/qa-2546-i-unavailable.png', fullPage: false });

    clearHitMetricFailure();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(liveCardDescription(page)).toHaveText(`${seeded.today.L.total.toLocaleString('en-US')} total hits`);
    const restored = await readCards(page);
    expectCounted(cardByKey(restored, 'live-hits'), seeded.today.L, 'recovered live');
});

test('J probable-match rows stay uncounted on all four cards', async ({ page }) => {
    await openHits(page);
    await chooseSelect(page, 'Type', 'Probable Match');
    await applyFilters(page);
    await expect(liveCardValue(page)).toHaveText(/^\s*0\s*$/);

    const cards = await readCards(page);
    for (const card of cards) {
        expectZero(card);
    }

    await expect(page.getByText('Type: Probable Match').first()).toBeVisible();
    await expect(page.getByText(seeded.plates.probable).first()).toBeVisible();
    await expect(page.getByText('Type: Live')).toHaveCount(0);
});

test('K plate, order, and client filters stay in sync with the table', async ({ page }) => {
    await openHits(page);
    await sidebar(page).getByLabel('Search by Vehicle Plate').fill(seeded.plates.historical);
    await sidebar(page).getByLabel('Search By Order ID').fill(seeded.orderIds.historical);
    await chooseSelect(page, 'Client(s)', seeded.clientName);
    await applyFilters(page);
    await expect(liveCardValue(page)).toHaveText(/^\s*0\s*$/);

    const cards = await readCards(page);
    expectCounted(cardByKey(cards, 'historical-hits'), { unique: 1, total: 1 }, 'combo historical');
    expectZero(cardByKey(cards, 'live-hits'));
    expectZero(cardByKey(cards, 'staging-live-hits'));
    expectZero(cardByKey(cards, 'staging-historical-hits'));
    await expect(page.getByText(seeded.plates.historical).first()).toBeVisible();
    await expect(page.getByText('Type: Historical').first()).toBeVisible();
    await expect(page.getByText('Type: Live')).toHaveCount(0);
});

test('L status, polling, and impersonation keep the shell up', async ({ page }) => {
    await openHits(page);
    const openCards = JSON.stringify(await readCards(page));

    const polling = sidebar(page).getByText('Live Polling', { exact: true });
    await expect(polling).toBeVisible();
    await polling.click();
    await applyFilters(page);
    expect(JSON.stringify(await readCards(page))).toBe(openCards);
    await expect(page.locator('[data-hit-metric-card]')).toHaveCount(4);

    await chooseSelect(page, 'Status', 'Hold');
    await applyFilters(page);
    await expect(liveCardDescription(page)).toHaveText('1 total hits');
    const held = await readCards(page);
    expectCounted(cardByKey(held, 'live-hits'), { unique: 1, total: 1 }, 'held live');
    expectZero(cardByKey(held, 'historical-hits'));
    await expect(page.getByText(seeded.plates.held).first()).toBeVisible();

    const cluster = sidebar(page).getByText('Location Cluster', { exact: true });
    if (await cluster.count() && await cluster.isEnabled()) {
        await cluster.click();
        await applyFilters(page);
        await expect(page.locator('[data-hit-metric-card]')).toHaveCount(4);
    }

    await rewriteForcedHttpsForLocalHttp(page);
    const impersonateUrl = new URL(seeded.impersonateUrl);
    const base = new URL(page.context().baseURL ?? 'https://127.0.0.1:8443');
    impersonateUrl.protocol = base.protocol;
    impersonateUrl.host = base.host;
    const response = await page.goto(impersonateUrl.toString(), { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 200).toBeLessThan(500);
    await page.goto('/app/hits', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-hit-metric-card]')).toHaveCount(4);
    const impersonated = await readCards(page);
    for (const card of impersonated) {
        expect(card.value).not.toBe('—');
    }
    expect(page.url()).not.toContain('login');
});
