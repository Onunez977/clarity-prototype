{{--
    Self-loading scan/hit/hotlist results table rendered inside an Otto chat bubble.

    Single source of truth for the chat widget, used two ways:
      - historically, MessageRenderer renders this partial for each persisted
        `otto_table` marker (type + searchId baked into the dataset);
      - live Vue chat renders `SearchWidget.vue` from the same marker; this
        Blade partial remains the PHP `MessageRenderer` twin.

    The island never trusts LLM text: it loads its own rows from
    `window._ottoInvestigationData` (served by the Investigation Results pane),
    filtered by the real `searchId`, and refreshes on `otto-investigation-loaded`.
--}}
<div
    data-otto-widget="search"
    data-widget="{{ $type ?? '' }}"
    data-search-id="{{ $searchId ?? '' }}"
    data-title="{{ $title ?? '' }}"
    x-data="{
        items: [],
        loaded: false,
        added: false,
        type: 'scan',
        searchId: '',
        title: '',
        selected: {},
        init() {
            this.type = this.$el.dataset.widget || 'scan';
            this.searchId = this.$el.dataset.searchId || '';
            this.title = this.$el.dataset.title || '';
            this.$nextTick(() => this.loadItems());
        },
        get isHit() { return this.type === 'hit'; },
        get isHotlist() { return this.type === 'hotlist'; },
        get isZone() { return this.type === 'zone'; },
        get headerTitle() {
            return this.title || (this.isZone ? 'Zone Results' : (this.isHotlist ? 'Assignment Results' : (this.isHit ? 'Hit Results' : 'Scan Results')));
        },
        get pageLabel() {
            const n = this.items.length;
            if (n < 1) {
                return '';
            }

            return n === 1 ? '1 most recent' : n + ' most recent';
        },
        get selectedCount() { return Object.values(this.selected).filter(Boolean).length; },
        matchesSearch(item) {
            if (item.searchId === this.searchId) {
                return true;
            }
            return Array.isArray(item.searchIds) && item.searchIds.includes(this.searchId);
        },
        isSelected(id) { return !!this.selected[id]; },
        toggleSelect(id) { this.selected = { ...this.selected, [id]: !this.selected[id] }; },
        addToReport() {
            if (!this.searchId) { return; }
            window.dispatchEvent(new CustomEvent('otto-add-widget-to-report', {
                detail: { widgetType: this.type, searchId: this.searchId },
            }));
            this.added = true;
        },
        addSelectedToReport() {
            const items = Object.keys(this.selected)
                .filter(id => this.selected[id])
                .map(id => ({ detailType: this.type, recordId: id }));
            if (!items.length) { return; }
            window.dispatchEvent(new CustomEvent('otto-add-details-to-report', { detail: { items } }));
            this.selected = {};
        },
        loadItems() {
            const data = window._ottoInvestigationData;
            if (!data) return;
            const pool = this.isZone
                ? (data.zones || [])
                : (this.isHotlist
                    ? (data.hotlists || [])
                    : (this.isHit ? (data.hits || []) : (data.scans || [])));
            this.items = pool.filter(i => this.matchesSearch(i));
            const search = (data.searches || []).find(s => s.searchId === this.searchId);
            if (search?.title) {
                this.title = search.title;
            }
            if (search || this.items.length > 0) {
                this.loaded = true;
            }
        },
        highlight(item, event) {
            window.dispatchEvent(new CustomEvent('otto-highlight', { detail: item }));
            if (this.isHotlist || this.isZone) {
                return;
            }
            window.dispatchEvent(new CustomEvent('otto-show-detail', { detail: { item, event } }));
        },
        clearHighlight() {
            window.dispatchEvent(new CustomEvent('otto-clear-highlight'));
            window.dispatchEvent(new CustomEvent('otto-hide-detail'));
        },
        showOnMap(item) {
            if (this.isHotlist) { return; }
            if (this.isZone) {
                const pane = document.querySelector('[data-otto-results-root]');
                if (pane && item.lat && item.lng) {
                    window.dispatchEvent(new CustomEvent('otto-pan-to', { detail: item }));
                    return;
                }
                if (item.editor_url) {
                    window.location.assign(item.editor_url);
                }
                return;
            }
            window.dispatchEvent(new CustomEvent('otto-pan-to', { detail: item }));
        },
        zoneDrivers(item) {
            const names = item.assigned_drivers || [];
            return names.length ? names.join(', ') : '—';
        },
        zoneZipLabel(item) {
            const n = Number(item.zip_count || 0);
            if (n < 1) {
                return '—';
            }
            return n === 1 ? '1 ZIP' : n + ' ZIPs';
        }
    }"
    @otto-investigation-loaded.window="loadItems()"
    class="not-prose my-3 overflow-hidden rounded-lg border shadow-sm"
    :class="isZone ? 'bg-violet-50 dark:bg-violet-900/10 border-violet-200 dark:border-violet-800' : (isHotlist ? 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800' : (isHit ? 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800' : 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800'))"
>
    <div
        class="flex items-center gap-2 border-b px-3 py-2"
        :class="isZone ? 'bg-violet-50 dark:bg-violet-900/10 border-violet-200 dark:border-violet-800' : (isHotlist ? 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800' : (isHit ? 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800' : 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800'))"
    >
        <span class="h-2 w-2 rounded-full" :class="isZone ? 'bg-violet-500' : (isHotlist ? 'bg-amber-500' : (isHit ? 'bg-red-500' : 'bg-blue-500'))"></span>
        <span class="text-xs font-semibold text-gray-600 dark:text-gray-300" x-text="headerTitle"></span>
        <span class="ml-auto text-[10px] text-gray-400 dark:text-gray-500" x-show="loaded && items.length > 0" x-text="pageLabel"></span>
        @if ($canAddToReport ?? false)
            <button
                type="button"
                @click="addToReport()"
                :disabled="added || !searchId"
                class="otto-add-to-report rounded-md border border-gray-300 px-2 py-0.5 text-[10px] font-medium text-gray-600 transition hover:bg-white disabled:cursor-default disabled:opacity-60 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                x-text="added ? 'Added to report ✓' : 'Add to report'"
            ></button>
        @endif
    </div>
    <div x-show="loaded && items.length > 0">
        <table class="w-full text-xs">
            <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                <tr>
                    @if ($canAddToReport ?? false)
                        <th class="otto-select-ui w-6 px-2 py-1.5"></th>
                    @endif
                    <th x-show="!isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Plate</th>
                    <th x-show="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Name</th>
                    <th x-show="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">No-scan</th>
                    <th x-show="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Assigned drivers</th>
                    <th x-show="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">ZIPs</th>
                    <th x-show="!isHotlist && !isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Time</th>
                    <th x-show="!isHotlist && !isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Kit / Driver</th>
                    <th x-show="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                    <th x-show="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">VIN</th>
                    <th x-show="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Vehicle</th>
                    <th x-show="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Client</th>
                    <th x-show="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Source</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="item in items" :key="item.type + '-' + item.id">
                    <tr
                        @mouseenter="highlight(item, $event)"
                        @mouseleave="clearHighlight()"
                        @click="showOnMap(item)"
                        class="cursor-pointer border-t border-gray-100 transition hover:bg-primary-50 dark:border-gray-800 dark:hover:bg-primary-900/20"
                        :class="isSelected(item.id) ? 'bg-primary-50 dark:bg-primary-900/20' : ''"
                    >
                        @if ($canAddToReport ?? false)
                            <td class="otto-select-ui px-2 py-1.5">
                                <input
                                    type="checkbox"
                                    :checked="isSelected(item.id)"
                                    @click.stop="toggleSelect(item.id)"
                                    class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600 dark:border-gray-600"
                                    aria-label="Select for report"
                                />
                            </td>
                        @endif
                        <td x-show="!isZone" class="px-3 py-1.5 font-mono font-semibold text-gray-800 dark:text-gray-200" x-text="item.plate"></td>
                        <td x-show="isZone" class="px-3 py-1.5 font-semibold text-gray-800 dark:text-gray-200" x-text="item.name || item.plate"></td>
                        <td x-show="isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.is_no_scan_zone ? 'No-scan' : '—'"></td>
                        <td x-show="isZone" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="zoneDrivers(item)"></td>
                        <td x-show="isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="zoneZipLabel(item)"></td>
                        <td x-show="!isHotlist && !isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.scanned_at"></td>
                        <td x-show="!isHotlist && !isZone" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.camera || '—'"></td>
                        <td x-show="isHotlist" class="px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.status_label || '—'"></td>
                        <td x-show="isHotlist" class="px-3 py-1.5 font-mono text-gray-500 dark:text-gray-400" x-text="item.vin || '—'"></td>
                        <td x-show="isHotlist" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.vehicle || '—'"></td>
                        <td x-show="isHotlist" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.client || '—'"></td>
                        <td x-show="isHotlist" class="px-3 py-1.5 text-gray-500 dark:text-gray-400" x-text="item.source || '—'"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    @if ($canAddToReport ?? false)
        <div class="otto-select-ui flex items-center justify-between gap-2 border-t border-gray-100 px-3 py-2 dark:border-gray-800" x-show="selectedCount > 0" x-cloak>
            <span class="text-[11px] text-gray-500 dark:text-gray-400" x-text="selectedCount + ' selected'"></span>
            <button
                type="button"
                @click="addSelectedToReport()"
                class="rounded-md border border-gray-300 px-2 py-0.5 text-[10px] font-medium text-gray-600 transition hover:bg-white dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
            >Add selected to report</button>
        </div>
    @endif
    <div x-show="!loaded" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
        Loading results…
    </div>
    <div x-show="loaded && items.length === 0" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
        No results found.
    </div>
</div>
