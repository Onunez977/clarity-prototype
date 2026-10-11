import { execSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page } from '@playwright/test';
import { loginViaNonProdBypass, rewriteForcedHttpsForLocalHttp } from './auth';

/**
 * DEV-2252 Scout ZIP-zone Playwright helpers.
 *
 * Fixture ZIPs (see seed-dev-2252-scout-zips.php):
 * - 75201 / 75202 — resolvable ZCTA MultiPolygons (Dallas-ish square)
 * - 75203 — tiger row with NULL the_geom (schema-permitted empty boundary)
 * - 75209 if absent, else 99999 — no tiger row (no-boundary)
 * - 7520 — 4-digit fragment (Excel-stripped leading-zero class); prefix-matches 75201
 *
 * Google Maps is stubbed. Isolated cloud often has no GOOGLE_MAPS_API_KEY;
 * zone-manager.js waits 15s for importLibrary and then no-ops. The stub is
 * enough for LatLngBounds / Polygon / addZip overlay attach without the SDK.
 */

export const MANAGE_ZONES_PATH = '/app/manage-zones';
export const ZONES_LIST_PATH = '/app/zones';

export const DEFAULT_VALID_ZIP = '75201';
export const DEFAULT_SECOND_VALID_ZIP = '75202';
export const DEFAULT_MISSING_ZIP = '75209';
export const DEFAULT_FRAGMENT_ZIP = '7520';
export const DEFAULT_THREE_DIGIT = '752';
export const DEFAULT_MALFORMED = '75ab';

export type Dev2252Seed = {
    adminEmail?: string;
    adminUserId?: number;
    adminCanAccessApp?: boolean;
    adminCanAccessManageZones?: boolean;
    noScoutEmail?: string;
    noScoutUserId?: number;
    noScoutCanAccessManageZones?: boolean;
    nonAdminEmail?: string;
    nonAdminUserId?: number;
    nonAdminCanAccessManageZones?: boolean;
    experimentalClarityScout?: boolean;
    validZip?: string;
    secondValidZip?: string;
    nullGeomZip?: string;
    missingZip?: string;
    fragmentZip?: string;
    tiger75201?: boolean;
    tiger75209?: boolean;
};

export type ZoneLookup = {
    found: boolean;
    id: number | null;
    name: string | null;
    zipCodes: string[];
    hasGeom: boolean;
    definition: { polygons?: unknown[]; zip_codes?: string[] } | null;
};

export type ZoneManagerState = {
    zipCodes: string[];
    zipError: string;
    zipOverlayCount: number;
    zipOverlayKeys: string[];
    polygonCount: number;
    editing: boolean;
};

const SEED_SCRIPT = resolve(process.cwd(), 'tests/e2e/helpers/seed-dev-2252-scout-zips.php');

export function runSeedScript(args: string[] = []): string {
    const cmd = ['php', JSON.stringify(SEED_SCRIPT), ...args.map((arg) => JSON.stringify(arg))].join(
        ' ',
    );

    return execSync(cmd, { encoding: 'utf8', timeout: 90_000 });
}

export function seedDev2252(): Dev2252Seed {
    const out = runSeedScript();
    const jsonLine = out
        .trim()
        .split('\n')
        .filter((line) => line.startsWith('{'))
        .at(-1);

    return JSON.parse(jsonLine ?? '{}') as Dev2252Seed;
}

export function lookupZoneByName(name: string): ZoneLookup {
    const out = runSeedScript(['zone-by-name', name]);
    const jsonLine = out
        .trim()
        .split('\n')
        .filter((line) => line.startsWith('{'))
        .at(-1);

    return JSON.parse(jsonLine ?? '{"found":false}') as ZoneLookup;
}

/**
 * Disk inventory for the PHPUnit guards this ticket already shipped.
 * Does not execute PHPUnit. Does not edit those files.
 */
export function assertPhpunitGuardInventory(): void {
    const manageGlob = [
        resolve(process.cwd(), 'tests/Filament/Pages/ManageZonesTest.php'),
    ];
    const materializerGlob = [
        resolve(process.cwd(), 'tests/Services/RunArea/MaterializerTest.php'),
    ];

    const managePath = manageGlob.find((path) => existsSync(path));
    const materializerPath = materializerGlob.find((path) => existsSync(path));

    expect(managePath, 'ManageZonesTest.php must remain on disk').toBeTruthy();
    expect(materializerPath, 'MaterializerTest.php must remain on disk').toBeTruthy();

    const manage = readFileSync(managePath as string, 'utf8');
    const materializer = readFileSync(materializerPath as string, 'utf8');

    expect(manage).toContain('test_zip_only_zone_with_no_census_boundary_is_rejected');
    expect(manage).toContain('test_unresolvable_zip_is_rejected_even_when_a_polygon_supplies_geometry');
    expect(manage).toContain("assertNotified('Zone not saved')");
    expect(manage).toContain('an unresolvable zip must not leave an invisible zone behind');

    expect(materializer).toContain('test_empty_inputs_leaves_geometry_null_and_does_not_stamp_metadata');
    expect(materializer).toContain(
        'test_unresolvable_reference_is_rejected_even_when_a_polygon_supplies_geometry',
    );
    expect(materializer).toContain('refusing to mark it materialized');
    expect(materializer).toContain('state:ZZ');
}

export async function installGoogleMapsStub(page: Page): Promise<void> {
    await page.route('https://maps.googleapis.com/**', async (route) => {
        await route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* maps stub */' });
    });
    await page.route('https://maps.gstatic.com/**', async (route) => {
        await route.fulfill({ status: 200, body: '' });
    });

    await page.addInitScript(() => {
        class LatLng {
            constructor(
                public _lat: number,
                public _lng: number,
            ) {}

            lat(): number {
                return this._lat;
            }

            lng(): number {
                return this._lng;
            }
        }

        class LatLngBounds {
            _pts: LatLng[] = [];

            extend(pt: LatLng): void {
                this._pts.push(pt);
            }

            isEmpty(): boolean {
                return this._pts.length === 0;
            }
        }

        class Map {
            constructor(
                public el: unknown,
                public opts: unknown,
            ) {}

            fitBounds(): void {}
        }

        class Polygon {
            _map: unknown = null;
            opts: { paths?: LatLng[] };

            constructor(opts: { paths?: LatLng[] } = {}) {
                this.opts = opts;
            }

            setMap(map: unknown): void {
                this._map = map;
            }

            getPath() {
                const path = this.opts.paths ?? [];

                return {
                    getLength: () => path.length,
                    getArray: () => path,
                    addListener: () => ({ remove() {} }),
                };
            }

            setOptions(): void {}

            addListener() {
                return { remove() {} };
            }
        }

        class DrawingManager {
            setMap(): void {}
            setDrawingMode(): void {}
        }

        const maps = {
            Map,
            Polygon,
            LatLng,
            LatLngBounds,
            drawing: { DrawingManager, OverlayType: { POLYGON: 'polygon' } },
            event: {
                addListener: () => ({ remove() {} }),
                removeListener: () => {},
            },
            importLibrary: async (name: string) => {
                if (name === 'maps') {
                    return { Map };
                }

                if (name === 'drawing') {
                    return { DrawingManager, OverlayType: { POLYGON: 'polygon' } };
                }

                return {};
            },
        };

        (window as unknown as { google: { maps: typeof maps } }).google = { maps };
    });
}

export async function loginScoutAdmin(page: Page, email: string): Promise<void> {
    await rewriteForcedHttpsForLocalHttp(page);
    await loginViaNonProdBypass(page, email, 'app');
}

export async function gotoManageZones(page: Page): Promise<void> {
    const response = await page.goto(MANAGE_ZONES_PATH, { waitUntil: 'domcontentloaded' });

    expect(response, 'Manage Zones navigation must return a response').not.toBeNull();
    expect(response?.status() ?? 0, 'Manage Zones must not 500').toBeLessThan(500);
}

export async function waitForZoneManager(page: Page): Promise<void> {
    await page.waitForFunction(
        () =>
            typeof window.Livewire !== 'undefined' &&
            typeof (window as unknown as { Alpine?: unknown }).Alpine !== 'undefined' &&
            typeof (window as unknown as { filamentZoneManager?: unknown }).filamentZoneManager ===
                'function',
        undefined,
        { timeout: 20_000 },
    );
}

export async function startNewZone(page: Page): Promise<void> {
    await waitForZoneManager(page);

    const newZone = page.getByRole('button', { name: 'New Zone' }).first();

    await expect(newZone).toBeVisible({ timeout: 15_000 });
    await newZone.click();
    await expect(page.getByPlaceholder('Add ZIP code…')).toBeVisible({ timeout: 15_000 });
}

export async function zoneManagerState(page: Page): Promise<ZoneManagerState> {
    return page.evaluate(() => {
        const alpine = (window as unknown as { Alpine?: { $data: (el: Element) => unknown } })
            .Alpine;

        if (!alpine) {
            throw new Error('Alpine is not on window');
        }

        for (const node of Array.from(document.querySelectorAll('[x-data]'))) {
            const data = alpine.$data(node) as {
                zipCodes?: string[];
                zipError?: string;
                zipOverlays?: Record<string, unknown[]>;
                polygonCount?: number;
                editing?: boolean;
                commitZipInput?: unknown;
            };

            if (data && Array.isArray(data.zipCodes) && typeof data.commitZipInput === 'function') {
                const overlays = data.zipOverlays ?? {};

                return {
                    zipCodes: [...data.zipCodes],
                    zipError: String(data.zipError ?? ''),
                    zipOverlayCount: Object.values(overlays).flat().length,
                    zipOverlayKeys: Object.keys(overlays),
                    polygonCount: Number(data.polygonCount ?? 0),
                    editing: Boolean(data.editing),
                };
            }
        }

        throw new Error('zone-manager Alpine component not found');
    });
}

export async function commitZip(page: Page, raw: string): Promise<void> {
    const input = page.getByPlaceholder('Add ZIP code…');

    await expect(input).toBeVisible();
    await input.fill(raw);
    await input.press('Enter');
}

export async function pasteZips(page: Page, raw: string): Promise<void> {
    const input = page.getByPlaceholder('Add ZIP code…');

    await expect(input).toBeVisible();
    await input.focus();
    await input.evaluate((el, text) => {
        const dt = new DataTransfer();
        dt.setData('text', text);
        el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true }));
    }, raw);
}

export async function fillZoneName(page: Page, name: string): Promise<void> {
    const field = page.getByLabel('Zone Name');

    await expect(field).toBeVisible();
    await field.fill(name);
}

export async function saveZone(page: Page): Promise<void> {
    const button = page.getByRole('button', { name: /Create Zone|Save Changes/ }).first();

    await expect(button).toBeVisible();
    await button.click();
}

export async function expectNotification(page: Page, title: string | RegExp): Promise<void> {
    await expect(page.getByText(title).first()).toBeVisible({ timeout: 15_000 });
}

export async function expectNoSuccessToast(page: Page): Promise<void> {
    await expect(page.getByText('Zone saved')).toHaveCount(0);
}

export async function injectDefinition(
    page: Page,
    definition: { polygons: unknown[]; zip_codes: string[] },
): Promise<void> {
    await page.evaluate(async (payload) => {
        const alpine = (window as unknown as { Alpine?: { $data: (el: Element) => unknown } })
            .Alpine;

        if (!alpine) {
            throw new Error('Alpine is not on window');
        }

        for (const node of Array.from(document.querySelectorAll('[x-data]'))) {
            const data = alpine.$data(node) as {
                commitZipInput?: unknown;
                $wire?: { set: (key: string, value: unknown) => Promise<void> };
            };

            if (data && typeof data.commitZipInput === 'function' && data.$wire) {
                await data.$wire.set('data.definition', payload);

                return;
            }
        }

        throw new Error('zone-manager $wire not found for definition inject');
    }, definition);
}

export async function injectMinimumPolygon(page: Page): Promise<void> {
    await page.evaluate(() => {
        const alpine = (window as unknown as { Alpine?: { $data: (el: Element) => unknown } })
            .Alpine;
        const google = (
            window as unknown as {
                google: {
                    maps: {
                        LatLng: new (lat: number, lng: number) => unknown;
                        Polygon: new (opts: unknown) => unknown;
                    };
                };
            }
        ).google;

        if (!alpine || !google) {
            throw new Error('Alpine / google.maps stub not ready');
        }

        for (const node of Array.from(document.querySelectorAll('[x-data]'))) {
            const data = alpine.$data(node) as {
                polygonOverlays?: unknown[];
                syncEditingState?: () => void;
                commitZipInput?: unknown;
            };

            if (!data || typeof data.syncEditingState !== 'function') {
                continue;
            }

            const ring = [
                new google.maps.LatLng(32.5, -97.0),
                new google.maps.LatLng(32.5, -96.5),
                new google.maps.LatLng(33.0, -96.5),
                new google.maps.LatLng(33.0, -97.0),
            ];
            const polygon = new google.maps.Polygon({ paths: ring });

            data.polygonOverlays = data.polygonOverlays ?? [];
            data.polygonOverlays.push(polygon);
            data.syncEditingState();

            return;
        }

        throw new Error('zone-manager Alpine component not found for polygon inject');
    });
}

export async function invokeAddZip(
    page: Page,
    token: string,
): Promise<{ result: string; zipCodes: string[]; zipOverlayKeys: string[] }> {
    return page.evaluate(async (zip) => {
        const alpine = (window as unknown as { Alpine?: { $data: (el: Element) => unknown } })
            .Alpine;
        const google = (
            window as unknown as {
                google: { maps: { LatLngBounds: new () => unknown } };
            }
        ).google;

        if (!alpine || !google) {
            throw new Error('Alpine / google.maps stub not ready');
        }

        for (const node of Array.from(document.querySelectorAll('[x-data]'))) {
            const data = alpine.$data(node) as {
                addZip?: (token: string, bounds: unknown) => Promise<string>;
                zipCodes?: string[];
                zipOverlays?: Record<string, unknown[]>;
            };

            if (!data || typeof data.addZip !== 'function') {
                continue;
            }

            const result = await data.addZip(zip, new google.maps.LatLngBounds());

            return {
                result,
                zipCodes: [...(data.zipCodes ?? [])],
                zipOverlayKeys: Object.keys(data.zipOverlays ?? {}),
            };
        }

        throw new Error('zone-manager addZip not found');
    }, token);
}

export function uniqueZoneName(letter: string): string {
    return `DEV-2252 e2e ${letter} ${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}
