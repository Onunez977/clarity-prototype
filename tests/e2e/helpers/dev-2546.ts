import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, type Locator, type Page } from '@playwright/test';

export type CardCounts = { unique: number; total: number };

export type Dev2546Seed = {
    adminEmail: string;
    adminUserId: number;
    orgId: number;
    kitId: number;
    clientName: string;
    lienholderName: string;
    plates: Record<string, string>;
    vins: Record<string, string>;
    orderIds: Record<string, string>;
    today: Record<string, CardCounts>;
    last7d: Record<string, CardCounts>;
    chSeeded: boolean;
    impersonateUrl: string;
};

export type MetricCard = {
    key: string;
    label: string;
    value: string;
    description: string;
};

const SEED_SCRIPT = resolve(process.cwd(), 'tests/e2e/helpers/seed-dev-2546-hits.php');

const CARD_KEYS = [
    'live-hits',
    'historical-hits',
    'staging-live-hits',
    'staging-historical-hits',
] as const;

export function seedDev2546(): Dev2546Seed {
    const out = execFileSync('php', [SEED_SCRIPT, 'seed'], {
        encoding: 'utf8',
        timeout: 180_000,
        maxBuffer: 16 * 1024 * 1024,
    });
    const jsonLine = out
        .trim()
        .split('\n')
        .filter((line) => line.startsWith('{') && line.includes('"adminEmail"'))
        .at(-1);

    if (!jsonLine) {
        throw new Error(`DEV-2546 seed did not print JSON.\n${out}`);
    }

    const seeded = JSON.parse(jsonLine) as Dev2546Seed;

    for (const hitType of ['L', 'H', 'S', 'Z']) {
        if (!seeded.today?.[hitType] || !seeded.last7d?.[hitType]) {
            throw new Error(`DEV-2546 seed is missing ${hitType} counts.\n${jsonLine}`);
        }
    }

    return seeded;
}

export function clearLoginRateLimit(): void {
    execFileSync('php', [SEED_SCRIPT, 'clear-login-limit'], {
        encoding: 'utf8',
        timeout: 30_000,
    });
}

export function markHitMetricFailure(): number {
    try {
        const out = execFileSync('php', [SEED_SCRIPT, 'fail-marker'], {
            encoding: 'utf8',
            timeout: 30_000,
        });
        const jsonLine = out.trim().split('\n').filter((line) => line.startsWith('{')).at(-1);

        return JSON.parse(jsonLine ?? '{}').marked ?? 0;
    } catch (error) {
        const stdout = (error as { stdout?: string }).stdout ?? '';
        const jsonLine = stdout.trim().split('\n').filter((line) => line.startsWith('{')).at(-1);

        if (jsonLine) {
            return JSON.parse(jsonLine).marked ?? 0;
        }

        throw error;
    }
}

export function clearHitMetricFailure(): void {
    execFileSync('php', [SEED_SCRIPT, 'clear-marker'], {
        encoding: 'utf8',
        timeout: 30_000,
    });
}

export function sidebar(page: Page): Locator {
    return page.locator('.filament-tables-filters-sidebar');
}

export async function readCards(page: Page): Promise<MetricCard[]> {
    const cards = page.locator('[data-hit-metric-card]');
    await expect(cards).toHaveCount(4, { timeout: 20_000 });

    const parsed: MetricCard[] = [];

    for (const key of CARD_KEYS) {
        const card = page.locator(`[data-hit-metric-card="${key}"]`);
        parsed.push({
            key,
            label: (await card.locator('.fi-wi-stats-overview-stat-label').innerText()).trim(),
            value: (await card.locator('.fi-wi-stats-overview-stat-value').innerText()).trim(),
            description: (await card.locator('.fi-wi-stats-overview-stat-description').innerText()).trim(),
        });
    }

    return parsed;
}

export function liveCardValue(page: Page): Locator {
    return page.locator('[data-hit-metric-card="live-hits"] .fi-wi-stats-overview-stat-value');
}

export function liveCardDescription(page: Page): Locator {
    return page.locator('[data-hit-metric-card="live-hits"] .fi-wi-stats-overview-stat-description');
}

export function cardByKey(cards: MetricCard[], key: string): MetricCard {
    const card = cards.find((item) => item.key === key);

    if (!card) {
        throw new Error(`Missing metric card ${key}`);
    }

    return card;
}

export function expectCounted(card: MetricCard, counts: CardCounts | undefined, label: string): void {
    if (!counts) {
        throw new Error(`${label} seed counts are missing (card ${card.key} shows ${card.value} / ${card.description})`);
    }

    expect(card.value, `${label} unique`).toBe(counts.unique.toLocaleString('en-US'));
    expect(card.description, `${label} total`).toBe(`${counts.total.toLocaleString('en-US')} total hits`);
}

export function expectZero(card: MetricCard): void {
    expect(card.value).toBe('0');
    expect(card.description).toBe('0 total hits');
}

async function waitForLivewirePost(page: Page, trigger: () => Promise<void>): Promise<void> {
    const pending = page.waitForRequest(
        (request) => request.method() === 'POST' && request.url().includes('livewire'),
        { timeout: 20_000 },
    );
    await trigger();
    const request = await pending;
    await request.response();
}

export async function applyFilters(page: Page): Promise<void> {
    await waitForLivewirePost(page, () => sidebar(page).getByRole('button', { name: 'Search' }).click());
    await expect(page.locator('[data-hit-metric-card="live-hits"]')).toBeVisible();
}

export async function resetFilters(page: Page): Promise<void> {
    await waitForLivewirePost(page, () => sidebar(page).getByRole('button', { name: 'Reset' }).click());
}

export async function chooseToggle(page: Page, fieldset: string, option: string): Promise<void> {
    const group = sidebar(page).locator('fieldset', { hasText: fieldset });
    const radio = group.getByRole('radio', { name: option, exact: true });

    if (await radio.count()) {
        const value = (await radio.first().getAttribute('value')) ?? option;
        const id = await radio.first().getAttribute('id');
        const pending = page.waitForResponse(
            (response) => {
                const request = response.request();

                return request.method() === 'POST'
                    && response.url().includes('livewire')
                    && (request.postData() ?? '').includes(value);
            },
            { timeout: 20_000 },
        );
        const target = id ? group.locator(`label[for="${id}"]`) : radio.first();
        await target.evaluate((element: HTMLElement) => element.click());
        await pending;
        await expect(radio.first()).toBeChecked();

        return;
    }

    await group.getByText(option, { exact: true }).click();
}

export async function chooseSelect(page: Page, label: string, option: string): Promise<void> {
    const field = sidebar(page).locator('.fi-fo-field', { hasText: label }).first();
    const native = field.locator('select');

    if (await native.count()) {
        await native.first().selectOption({ label: option });

        return;
    }

    const trigger = field.getByRole('button').first();
    await trigger.click();

    const choice = page.getByRole('option', { name: option, exact: true });
    await expect(choice).toBeVisible({ timeout: 10_000 });
    await choice.click();
}

export async function columnRowCount(page: Page): Promise<number> {
    const buckets = await page.locator('[data-hit-metric-card]').evaluateAll((elements) => {
        return elements.map((element) => Math.round(element.getBoundingClientRect().top / 12));
    });

    return new Set(buckets).size;
}

export async function cardsOverflow(page: Page): Promise<boolean> {
    return page.locator('.fi-wi-stats-overview').evaluate((element) => {
        return element.scrollWidth > element.clientWidth + 2;
    });
}
