// Vehicle icon definitions used by both the live map marker (ScannerMap.vue)
// and the dashboard legend pill (Dashboard.vue). Single source of truth so
// the two can't drift apart.
//
// Source paths are pulled from established icon libraries (poorly hand-drawn
// silhouettes don't read at marker scale):
//
//   scanning_car → Material Icons       `directions_car`  — Apache 2.0
//   repo_truck   → Material Design Icons `tow-truck`      — Apache 2.0 / Pictogrammers
//   flatbed      → Phosphor Icons        `truck-trailer`  — MIT
//
// `local_shipping` (Material) was the previous repo_truck choice, but it
// reads as a generic box delivery truck — visually indistinguishable from a
// UPS van. MDI's `tow-truck` has the wheel-lift boom on the back, which is
// the defining visual feature of a repossession vehicle.
//
// Bootstrap's `truck-flatbed` was the previous flatbed choice, but its bed
// collapses to a hairline strip when rendered as a solid fill — only the cab
// and wheels showed on the map. Phosphor's truck-trailer is a true tractor
// + flat trailer silhouette where the trailer bed is a dominant filled rect.
//
// Each entry uses its source library's native viewBox. The map renderer
// (google.maps.Symbol) and the legend renderer (inline <svg>) both consume
// these defs and apply the per-icon scale/anchor.

export const VEHICLE_ICON_DEFS = {
    scanning_car: {
        path: 'M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z',
        viewBox: '0 0 24 24',
        anchor: [12, 12],
        scale: 1.4,
    },
    repo_truck: {
        path: 'M15,10H20.39L17.06,6H15V10M17.5,18.5C17.89,18.5 18.24,18.37 18.54,18.07C18.84,17.77 19,17.42 19,17C19,16.61 18.84,16.26 18.54,15.96C18.24,15.66 17.89,15.5 17.5,15.5C17.08,15.5 16.73,15.66 16.43,15.96C16.13,16.26 16,16.61 16,17C16,17.42 16.13,17.77 16.43,18.07C16.73,18.37 17.08,18.5 17.5,18.5M6,18.5C6.44,18.5 6.8,18.37 7.08,18.07C7.36,17.77 7.5,17.42 7.5,17C7.5,16.61 7.36,16.26 7.08,15.96C6.8,15.66 6.44,15.5 6,15.5C5.56,15.5 5.2,15.66 4.92,15.96C4.64,16.26 4.5,16.61 4.5,17C4.5,17.42 4.64,17.77 4.92,18.07C5.2,18.37 5.56,18.5 6,18.5M18,4L23,10V17H20.5C20.5,17.83 20.19,18.53 19.59,19.13C19,19.72 18.3,20 17.5,20C16.67,20 15.97,19.72 15.38,19.13C14.78,18.53 14.5,17.83 14.5,17H9C9,17.83 8.7,18.53 8.11,19.13C7.5,19.72 6.81,20 6,20C5.19,20 4.5,19.72 3.89,19.13C3.3,18.53 3,17.83 3,17H1V13H9.19L3,8.11V11H1V5H2L13,11.06V4H18Z',
        viewBox: '0 0 24 24',
        anchor: [12, 12],
        scale: 1.4,
    },
    flatbed: {
        path: 'M224,96.8V96a56.06,56.06,0,0,0-56-56h-8a16,16,0,0,0-16,16V176H128V72a8,8,0,0,0-8-8H16A16,16,0,0,0,0,80V184a32,32,0,0,0,56,21.13A32,32,0,0,0,111,192h82a32,32,0,0,0,63-8V136A40.07,40.07,0,0,0,224,96.8ZM32,200a16,16,0,1,1,16-16A16,16,0,0,1,32,200Zm48,0a16,16,0,1,1,16-16A16,16,0,0,1,80,200Zm144,0a16,16,0,1,1,16-16A16,16,0,0,1,224,200Z',
        viewBox: '0 0 256 256',
        anchor: [128, 128],
        scale: 0.13,
    },
};

// All source icons are drawn with the vehicle facing right (east) in their
// native coordinate system. The marker renders them in this fixed orientation
// (no heading rotation) so the icon stays upright and matches the legend pill.
export const VEHICLE_FILL = '#3B82F6';   // tailwind blue-500, matches the default dot
export const VEHICLE_STROKE = '#FFFFFF';

/**
 * Human-readable display label for a vehicle_type enum value. Mirrors the
 * server-side VehicleTypeEnum::title() output so the dashboard legend and
 * the Filament admin show the same wording.
 *
 * Returns null when no vehicle type is set — the caller decides whether to
 * hide the label entirely or render a fallback.
 */
const VEHICLE_TYPE_LABELS = {
    scanning_car: 'Scanning Car',
    repo_truck: 'Repo Truck',
    flatbed: 'Flatbed',
};

export function vehicleTypeLabel(vehicleType) {
    return VEHICLE_TYPE_LABELS[vehicleType] ?? null;
}

/**
 * Returns an inline SVG markup string for the dashboard legend pill — a small
 * upright rendering of the same icon the map marker uses. When vehicleType is
 * null or unknown, falls back to a filled blue circle so the legend always has
 * something visible.
 *
 * size is the rendered pixel size (width = height). Default 20px reads well at
 * pill scale.
 */
export function legendIconSvg(vehicleType, size = 20) {
    const def = VEHICLE_ICON_DEFS[vehicleType];
    if (!def) {
        // Default kit dot — matches defaultKitIcon() in ScannerMap.vue
        const r = size * 0.4;
        const c = size / 2;
        return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">`
            + `<circle cx="${c}" cy="${c}" r="${r}" fill="${VEHICLE_FILL}" stroke="${VEHICLE_STROKE}" stroke-width="1.5"/>`
            + `</svg>`;
    }
    // No stroke on the legend rendering — the white pill background already
    // provides enough contrast, and stroke widths don't normalize cleanly
    // across the three different source viewBoxes (24×24 vs 256×256).
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="${def.viewBox}">`
        + `<path d="${def.path}" fill="${VEHICLE_FILL}"/>`
        + `</svg>`;
}
