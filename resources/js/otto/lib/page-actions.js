/**
 * Page-action presentation, humanization, and Filament Accept dispatch.
 * Port of otto-stream.js rolling search cards + Livewire.dispatch('otto-page-action').
 */

export const SEARCH_INSTEAD_PROMPT_PREFIX =
    'Run this search yourself and show the results here in the chat instead of changing the page';

export const SEARCH_INSTEAD_PROMPT_FALLBACK =
    'Run that search yourself and show the results here in the chat instead of changing the page.';

export function isSearchInsteadPrompt(content) {
    const text = String(content ?? '');

    return text === SEARCH_INSTEAD_PROMPT_FALLBACK || text.startsWith(SEARCH_INSTEAD_PROMPT_PREFIX);
}

export function searchInsteadPromptFromRows(rows) {
    const what = (rows || []).map((r) => r.summary).filter(Boolean).join('; ');

    return what !== ''
        ? `${SEARCH_INSTEAD_PROMPT_PREFIX}: ${what}.`
        : SEARCH_INSTEAD_PROMPT_FALLBACK;
}

export function pageActionPresentation(name) {
    if (name === 'add-officer-hotplate') {
        return {
            heading: 'Hotplate',
            applyLabel: 'Add to Hotplates',
            reapplyLabel: 'Add again',
            showSearchInstead: false,
            emptyLabel: 'No plate details.',
            offPageNote: 'Open the Officer page to add this plate again.',
        };
    }

    if (name === 'create-zone') {
        return {
            heading: 'Create zone',
            applyLabel: 'Accept',
            reapplyLabel: 'Create again',
            showSearchInstead: false,
            emptyLabel: 'No zone details.',
            offPageNote: '',
        };
    }

    if (name === 'update-zone') {
        return {
            heading: 'Update zone',
            applyLabel: 'Accept',
            reapplyLabel: 'Update again',
            showSearchInstead: false,
            emptyLabel: 'No zone details.',
            offPageNote: '',
        };
    }

    if (name === 'open-zone-editor') {
        return {
            heading: 'Zones map',
            applyLabel: 'Open Zones map',
            reapplyLabel: 'Open Zones map',
            showSearchInstead: false,
            emptyLabel: 'No zone details.',
            offPageNote: '',
        };
    }

    return {
        heading: 'Search',
        applyLabel: 'Apply search',
        reapplyLabel: 'Re-Apply search',
        showSearchInstead: true,
        emptyLabel: 'No active filters.',
        offPageNote: 'Open the page this was suggested for to apply this search.',
    };
}

export function pageActionChangeIsClear(value) {
    return value === '' || value === null || value === undefined || (Array.isArray(value) && value.length === 0);
}

export function humanizePageActionSummary(summary) {
    if (typeof summary !== 'string' || summary === '') {
        return summary;
    }

    const replacements = [
        [/\blast_90_days\b/gi, 'the last 90 days'],
        [/\blast_30_days\b/gi, 'the last 30 days'],
        [/\blast_7_days\b/gi, 'the last 7 days'],
        [/\blast_90d\b/gi, 'the last 90 days'],
        [/\blast_30d\b/gi, 'the last 30 days'],
        [/\blast_7d\b/gi, 'the last 7 days'],
        [/\btime_range\b/g, 'time range'],
    ];

    let out = summary;
    replacements.forEach(([pattern, phrase]) => {
        out = out.replace(pattern, phrase);
    });

    return out;
}

/**
 * @param {Array<{ actionId: string, name: string, changes: Array<Record<string, unknown>> }>} cards
 * @param {number} targetIndex
 */
export function computeSearchUpTo(cards, targetIndex) {
    const target = cards[targetIndex];
    const targetName = target?.name || '';
    const cumulative = new Map();
    const changed = new Map();

    for (let i = 0; i <= targetIndex; i += 1) {
        const card = cards[i];
        const sameAction = !targetName || !card.name || card.name === targetName;
        if (!sameAction) {
            continue;
        }
        (card.changes || []).forEach((change) => {
            if (!change.field || change.field === '__all__') {
                return;
            }
            if (i === targetIndex) {
                changed.set(change.field, cumulative.has(change.field) ? 'Updated' : 'New');
            }
            cumulative.set(change.field, {
                value: change.value,
                summary: change.summary,
                label: change.label,
            });
        });
    }

    const payload = {};
    const rows = [];
    cumulative.forEach((entry, field) => {
        payload[field] = entry.value;
        if (!pageActionChangeIsClear(entry.value)) {
            rows.push({
                summary: humanizePageActionSummary(entry.summary || entry.label || field),
                badge: changed.get(field) || null,
            });
        }
    });

    return { payload, rows };
}

/**
 * Dispatch a confirmed page action to the Filament page adapter.
 * No-op if Livewire is not mounted (Org Map).
 */
export function dispatchPageAction(pageType, name, payload) {
    if (pageType === 'officer') {
        const sectionByAction = {
            'apply-officer-scan-filters': 'scans',
            'apply-officer-recent-hits-filters': 'recentHits',
            'add-officer-hotplate': 'myHotplates',
        };
        const section = sectionByAction[name];
        try {
            const store = window.Alpine?.store?.('officer');
            if (section && store) {
                store.activeSection = section;
                if (section === 'recentHits') {
                    store.newHitsCount = 0;
                }
            }
        } catch {
            /* Alpine store may be absent off the Officer page */
        }
    }

    try {
        window.Livewire?.dispatch('otto-page-action', { pageType, name, payload });
    } catch {
        /* the adapter simply won't apply if Livewire isn't ready */
    }
}

/**
 * Normalize a page_actions payload from HTTP JSON or a live Echo event.
 *
 * @param {Record<string, unknown>} raw
 */
export function normalizePageAction(raw) {
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    const actionId = String(raw.action_id ?? raw.actionId ?? '');
    const name = raw.name || '';
    const changes = Array.isArray(raw.changes) && raw.changes.length
        ? raw.changes
        : [{ field: '__all__', label: '', summary: raw.summary || '', value: null }];

    return {
        actionId,
        pageType: raw.page_type ?? raw.pageType ?? '',
        name,
        summary: raw.summary || '',
        changes,
        requiresConfirmation: raw.requires_confirmation !== false && raw.requiresConfirmation !== false,
    };
}

export function normalizePageActions(list) {
    if (!Array.isArray(list)) {
        return [];
    }

    return list
        .map((item) => {
            if (typeof item === 'string') {
                try {
                    return normalizePageAction(JSON.parse(item));
                } catch {
                    return null;
                }
            }

            return normalizePageAction(item);
        })
        .filter(Boolean);
}

export const TOOL_STATUS = {
    ScanSearchTool: 'Searching scans…',
    HitSearchTool: 'Searching hits…',
    HotlistSearchTool: 'Searching assignments…',
    LocationSearchTool: 'Searching near a location…',
    GeoZoneScanSearchTool: 'Searching in a geographic zone…',
    MultiGeoSearchTool: 'Searching across locations…',
    ConvoySearchTool: 'Looking for travel companions…',
    ConvoySearchAdvancedTool: 'Running a detailed convoy analysis…',
    CameraActivityTool: 'Checking camera activity…',
    GeocodingTool: 'Looking up that address…',
    KnowledgeBaseSearchTool: 'Looking something up…',
    ScoutDriverPositionTool: 'Looking up drivers…',
    ZoneSearchTool: 'Searching zones…',
    ProposeCreateZoneTool: 'Preparing a zone…',
    ProposeUpdateZoneTool: 'Updating a zone…',
    ShowResultDetailsTool: 'Pulling up the details…',
    ApplyScanSearchFiltersTool: 'Updating scan filters…',
    ApplyHitSearchFiltersTool: 'Updating hit filters…',
    ApplyOfficerScanFiltersTool: 'Updating scan filters…',
    ApplyOfficerRecentHitsFiltersTool: 'Updating hit filters…',
    AddOfficerHotplateTool: 'Adding a hot plate…',
    ViewOfficerHotplatesTool: 'Checking hot plates…',
    AddReportBlockTool: 'Updating the report…',
    AddReportWidgetTool: 'Adding results to the report…',
    AddReportResultDetailsTool: 'Adding details to the report…',
    EditReportBlockTool: 'Revising the report…',
    RemoveReportBlockTool: 'Updating the report…',
};

export const STARTER_PROMPTS = [
    { label: 'Recent scans', hint: 'from today', prompt: 'Show me the most recent scans from today' },
    { label: 'Recent hits', hint: 'from the last 7 days', prompt: 'Show me recent hotlist hits from the last 7 days' },
    { label: 'Plate lookup', hint: 'search for a specific plate', prompt: 'Search for plate ABC1234 in the last 30 days' },
    { label: 'What can you do?', hint: 'learn about my capabilities', prompt: 'What can you help me with?' },
];
