import { expect, type Page } from '@playwright/test';
import { loginAsAdmin, rewriteForcedHttpsForLocalHttp } from './auth';

/**
 * Otto Vue-island helpers for PR #333 cloud E2E.
 *
 * Live Bedrock turns need AWS_BEDROCK_* / BEDROCK_* secrets. Seeded history
 * threads from OttoSeeder exercise widgets without calling the model.
 */
export async function openAppWithOtto(page: Page): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);
    await loginAsAdmin(page);
    await page.goto('/app', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#otto-root')).toBeAttached({ timeout: 30_000 });
    await expect
        .poll(async () => page.evaluate(() => !!window.__OTTO_CONFIG__), { timeout: 30_000 })
        .toBe(true);
}

export async function openOttoAssistant(page: Page): Promise<void> {
    const dialog = page.getByRole('dialog', { name: 'Otto assistant' });

    if (await dialog.isVisible().catch(() => false)) {
        return;
    }

    await page.getByRole('button', { name: /open otto assistant/i }).click();
    await expect(dialog).toBeVisible({ timeout: 15_000 });
}

export async function acceptOttoDisclaimerIfPresent(page: Page): Promise<void> {
    const understand = page.getByRole('button', { name: /^i understand$/i });

    if (await understand.isVisible().catch(() => false)) {
        await understand.click();
        await expect(understand).toHaveCount(0, { timeout: 10_000 });
    }
}

export async function openOttoHistory(page: Page): Promise<void> {
    const dialog = page.getByRole('dialog', { name: 'Otto assistant' });
    const search = dialog.getByPlaceholder('Search history...');

    if (await search.isVisible().catch(() => false)) {
        return;
    }

    await dialog.getByRole('button', { name: /conversation history/i }).click();
    await expect(search).toBeVisible({ timeout: 10_000 });
}

export async function askOtto(page: Page, prompt: string): Promise<void> {
    const composer = page.getByPlaceholder('Ask Otto something...');

    await expect(composer).toBeVisible();
    await composer.fill(prompt);
    await page.getByRole('button', { name: /^send$/i }).click();
}

export function bedrockConfigured(): boolean {
    const key =
        process.env.BEDROCK_KEY ||
        process.env.AWS_BEDROCK_ACCESS_KEY_ID ||
        process.env.AWS_ACCESS_KEY_ID;
    const secret =
        process.env.BEDROCK_SECRET ||
        process.env.AWS_BEDROCK_SECRET_ACCESS_KEY ||
        process.env.AWS_SECRET_ACCESS_KEY;

    return Boolean(key && secret);
}
