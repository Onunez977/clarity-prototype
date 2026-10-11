/**
 * Custom OverlayView popup for Otto maps.
 * Never use the Maps InfoWindow class — SVG data-URI pins report a 300×150
 * intrinsic size and that popup parks miles from the marker.
 */

import { driverSeenAt, fmtRelative, fmtTime } from './format.js';

export function esc(s) {
    if (!s) {
        return '';
    }
    const d = document.createElement('div');
    d.textContent = s;

    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

export function pinIcon(google, color) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="36" viewBox="0 0 24 36">
                <path fill="${color}" stroke="#fff" stroke-width="1.5" d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24C24 5.4 18.6 0 12 0z"/>
                <circle cx="12" cy="12" r="4.5" fill="#fff"/>
            </svg>`;

    return {
        url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
        size: new google.maps.Size(24, 36),
        scaledSize: new google.maps.Size(24, 36),
        origin: new google.maps.Point(0, 0),
        anchor: new google.maps.Point(12, 36),
    };
}

export function pinColor(item) {
    if (item.type === 'zone') {
        return item.color || '#9ca3af';
    }
    if (item.type === 'hit') {
        return '#ef4444';
    }
    if (item.type === 'scout_driver') {
        return '#10b981';
    }

    return '#3b82f6';
}

export function zoneRings(geometry) {
    if (!geometry || !Array.isArray(geometry.coordinates)) {
        return [];
    }
    if (geometry.type === 'Polygon') {
        return geometry.coordinates[0] ? [geometry.coordinates[0]] : [];
    }
    if (geometry.type === 'MultiPolygon') {
        return geometry.coordinates.map((polygon) => polygon[0]).filter(Boolean);
    }

    return [];
}

export function popupHtml(item) {
    if (item.type === 'scout_driver') {
        return driverPopupHtml(item);
    }
    if (item.type === 'zone') {
        return zonePopupHtml(item);
    }

    const time = fmtTime(item.scanned_at);
    const badge = item.type === 'hit'
        ? '<span style="display:inline-flex;align-items:center;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;background:#ef4444;color:#fff">HIT</span>'
        : '<span style="display:inline-flex;align-items:center;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;background:#3b82f6;color:#fff">SCAN</span>';

    let h = '<div style="width:300px;font-size:12px;line-height:1.35;overflow:hidden">';

    if (item.car_image) {
        h += '<div style="position:relative">';
        h += '<img src="' + esc(item.car_image) + '" alt="Vehicle" style="width:100%;height:130px;object-fit:cover;display:block;background:#f3f4f6" onerror="this.src=\'/img/no-image.svg\'">';
        if (item.plate_image) {
            h += '<img src="' + esc(item.plate_image) + '" alt="Plate" style="position:absolute;bottom:6px;right:6px;height:32px;border-radius:4px;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.3);background:#f3f4f6" onerror="this.style.display=\'none\'">';
        }
        h += '<div style="position:absolute;top:6px;left:6px">' + badge + '</div>';
        h += '</div>';
    }

    h += '<div style="padding:8px 12px">';
    h += '<div style="display:flex;align-items:center;justify-content:space-between">';
    h += '<span style="font-size:15px;font-weight:700;font-family:ui-monospace,monospace">' + esc(item.plate) + '</span>';
    if (item.state) {
        h += '<span style="font-size:11px;color:#9ca3af">' + esc(item.state) + '</span>';
    }
    h += '</div>';
    h += '<div style="font-size:11px;color:#6b7280;margin-top:4px">' + esc(time) + '</div>';

    if (item.type === 'hit' && item.hotlist_name) {
        h += '<div style="margin-top:8px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;padding:6px 10px">';
        h += '<div style="font-size:11px;font-weight:700;color:#b91c1c">' + esc(item.hotlist_name) + '</div>';
        if (item.hotlist_description) {
            h += '<div style="font-size:11px;color:#dc2626;margin-top:2px">' + esc(item.hotlist_description) + '</div>';
        }
        if (item.case_number) {
            h += '<div style="font-size:10px;color:#ef4444;margin-top:2px">Order: ' + esc(item.case_number) + '</div>';
        }
        h += '</div>';
    }

    const fields = [];
    if (item.vehicle_make) {
        fields.push(['Make', item.vehicle_make]);
    }
    if (item.vehicle_model) {
        fields.push(['Model', item.vehicle_model]);
    }
    if (item.vehicle_color) {
        fields.push(['Color', item.vehicle_color]);
    }
    if (fields.length > 0) {
        h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-top:8px">';
        fields.forEach(([label, value]) => {
            h += '<div><span style="font-size:10px;color:#9ca3af">' + label + '</span>';
            h += '<div style="font-size:11px;color:#374151">' + esc(value) + '</div></div>';
        });
        h += '</div>';
    }

    if (item.camera) {
        h += '<div style="font-size:11px;color:#9ca3af;margin-top:8px;padding-top:6px;border-top:1px solid #f3f4f6">' + esc(item.camera) + '</div>';
    }

    h += '</div></div>';

    return h;
}

function driverPopupHtml(item) {
    const time = fmtRelative(driverSeenAt(item));
    const name = item.driver_name || item.plate || 'Driver';
    const kit = item.kit_name || item.camera || '';
    const place = item.place || '';

    let h = '<div style="width:280px;font-size:12px;line-height:1.35;padding:10px 12px">';
    h += '<span style="display:inline-flex;align-items:center;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;background:#10b981;color:#fff">DRIVER</span>';
    h += '<div style="margin-top:8px;font-size:15px;font-weight:700">' + esc(name) + '</div>';
    if (kit) {
        h += '<div style="font-size:11px;color:#6b7280;margin-top:2px">' + esc(kit) + '</div>';
    }
    if (place) {
        h += '<div style="margin-top:8px;font-size:12px;color:#111827">' + esc(place) + '</div>';
    } else {
        h += '<div style="margin-top:8px;font-size:11px;color:#6b7280">Street address unavailable for this GPS ping.</div>';
    }
    if (time) {
        h += '<div style="font-size:11px;color:#9ca3af;margin-top:8px;padding-top:6px;border-top:1px solid #f3f4f6">' + esc(time) + '</div>';
    }
    h += '</div>';

    return h;
}

function zonePopupHtml(item) {
    const name = item.name || item.plate || 'Zone';
    const drivers = (item.assigned_drivers || []).join(', ');
    const zips = Number(item.zip_count || 0);
    const zipLabel = zips < 1 ? '' : (zips === 1 ? '1 ZIP' : zips + ' ZIPs');

    let h = '<div style="width:260px;font-size:12px;line-height:1.35;padding:10px 12px">';
    h += item.is_no_scan_zone
        ? '<span style="display:inline-flex;align-items:center;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;background:#9ca3af;color:#fff">NO-SCAN</span>'
        : '<span style="display:inline-flex;align-items:center;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;background:#8b5cf6;color:#fff">ZONE</span>';
    h += '<div style="margin-top:8px;font-size:15px;font-weight:700">' + esc(name) + '</div>';
    if (drivers) {
        h += '<div style="font-size:11px;color:#6b7280;margin-top:4px">' + esc(drivers) + '</div>';
    }
    if (zipLabel) {
        h += '<div style="font-size:11px;color:#9ca3af;margin-top:8px;padding-top:6px;border-top:1px solid #f3f4f6">' + esc(zipLabel) + '</div>';
    }
    h += '</div>';

    return h;
}

/**
 * @param {typeof google} google
 * @param {google.maps.LatLng} position
 * @param {string} html
 * @param {() => void} onClose
 */
export function makePopupOverlay(google, position, html, onClose) {
    const overlay = new google.maps.OverlayView();
    overlay.position = position;

    overlay.onAdd = function () {
        const wrap = document.createElement('div');
        wrap.setAttribute('data-otto-map-popup', '');
        wrap.style.cssText = 'position:absolute;transform:translate(-50%,calc(-100% - 8px));z-index:1;pointer-events:auto;filter:drop-shadow(0 4px 12px rgba(0,0,0,.22))';
        wrap.innerHTML = '<div style="position:relative;background:#fff;border-radius:8px;overflow:hidden">'
            + '<button type="button" data-otto-popup-close aria-label="Close" style="position:absolute;top:4px;right:4px;z-index:2;width:22px;height:22px;border:0;border-radius:999px;background:rgba(255,255,255,.92);color:#374151;font-size:16px;line-height:20px;cursor:pointer">×</button>'
            + '<div data-otto-popup-body></div></div>'
            + '<div style="width:0;height:0;margin:0 auto;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff"></div>';
        wrap.querySelector('[data-otto-popup-body]').innerHTML = html;
        wrap.addEventListener('click', (e) => e.stopPropagation());
        wrap.querySelector('[data-otto-popup-close]').addEventListener('click', (e) => {
            e.stopPropagation();
            onClose();
        });
        this.div = wrap;
        this.getPanes().floatPane.appendChild(wrap);
    };

    overlay.draw = function () {
        const projection = this.getProjection();
        if (!projection || !this.div) {
            return;
        }
        const point = projection.fromLatLngToDivPixel(this.position);
        if (!point) {
            return;
        }
        this.div.style.left = point.x + 'px';
        this.div.style.top = point.y + 'px';
    };

    overlay.onRemove = function () {
        this.div?.remove();
        this.div = null;
    };

    return overlay;
}
