@props(['rows', 'columns', 'valueKey' => 'value', 'toggleable' => false, 'statusColumnMap' => []])

@php
    $maxValue = collect($rows)->max($valueKey);
    $columnKeys = array_keys($columns);
    // Identify which column keys are status columns (toggleable)
    $statusColumnKeys = array_values($statusColumnMap);
@endphp

<div
    x-data="{
        sortKey: '',
        sortDir: 'asc',
        rows: @js($rows),
        toggleable: @js($toggleable),
        statusColumnMap: @js($statusColumnMap),
        statusColumnKeys: @js($statusColumnKeys),
        isStatusColumn(key) {
            return this.statusColumnKeys.includes(key);
        },
        isColumnVisible(key) {
            if (!this.toggleable || !this.isStatusColumn(key)) return true;
            // Find the status label for this column key
            for (const [label, colKey] of Object.entries(this.statusColumnMap)) {
                if (colKey === key) return activeStatuses.includes(label);
            }
            return true;
        },
        getRowTotal(row) {
            if (!this.toggleable) return row['total'] ?? 0;
            let sum = 0;
            for (const [label, colKey] of Object.entries(this.statusColumnMap)) {
                if (activeStatuses.includes(label)) {
                    sum += (row[colKey] ?? 0);
                }
            }
            return sum;
        },
        sort(key) {
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortKey = key;
                this.sortDir = 'desc';
            }
            const dir = this.sortDir === 'asc' ? 1 : -1;
            this.rows.sort((a, b) => {
                let aVal = key === 'total' && this.toggleable ? this.getRowTotal(a) : (a[key] ?? 0);
                let bVal = key === 'total' && this.toggleable ? this.getRowTotal(b) : (b[key] ?? 0);
                if (typeof aVal === 'string' && typeof bVal === 'string') {
                    return dir * aVal.localeCompare(bVal);
                }
                return dir * (aVal - bVal);
            });
        }
    }"
    class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"
>
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                @foreach ($columns as $key => $label)
                    <th
                        x-show="isColumnVisible('{{ $key }}')"
                        class="px-4 py-3 font-medium text-gray-600 dark:text-gray-400 cursor-pointer select-none hover:text-gray-900 dark:hover:text-gray-200 transition-colors {{ $loop->first ? 'text-left' : 'text-right' }}"
                        x-on:click="sort('{{ $key }}')"
                    >
                        <span class="inline-flex items-center gap-1 {{ $loop->first ? '' : 'flex-row-reverse' }}">
                            {{ $label }}
                            <span class="inline-flex flex-col -space-y-2">
                                <svg
                                    class="w-3 h-3 transition-colors"
                                    :class="sortKey === '{{ $key }}' && sortDir === 'asc'
                                        ? 'text-gray-900 dark:text-white'
                                        : 'text-gray-300 dark:text-gray-600'"
                                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                                >
                                    <path fill-rule="evenodd" d="M10 3a.75.75 0 01.55.24l3.25 3.5a.75.75 0 11-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 01-1.1-1.02l3.25-3.5A.75.75 0 0110 3z" clip-rule="evenodd" />
                                </svg>
                                <svg
                                    class="w-3 h-3 transition-colors"
                                    :class="sortKey === '{{ $key }}' && sortDir === 'desc'
                                        ? 'text-gray-900 dark:text-white'
                                        : 'text-gray-300 dark:text-gray-600'"
                                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                                >
                                    <path fill-rule="evenodd" d="M10 17a.75.75 0 01-.55-.24l-3.25-3.5a.75.75 0 111.1-1.02L10 15.148l2.7-2.908a.75.75 0 111.1 1.02l-3.25 3.5A.75.75 0 0110 17z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <template x-if="rows.length === 0">
                <tr>
                    <td colspan="{{ count($columns) }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                        No data available for this period.
                    </td>
                </tr>
            </template>
            <template x-for="(row, index) in rows" :key="index">
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                    @foreach ($columns as $key => $label)
                        @if ($key === 'total' && $toggleable)
                            <td
                                class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"
                            >
                                <span
                                    class="font-semibold"
                                    x-text="getRowTotal(row).toLocaleString()"
                                ></span>
                            </td>
                        @elseif ($loop->first)
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                                <span x-text="row['{{ $key }}']"></span>
                            </td>
                        @else
                            <td
                                x-show="isColumnVisible('{{ $key }}')"
                                class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"
                            >
                                <span
                                    class="font-semibold"
                                    x-text="typeof row['{{ $key }}'] === 'number' ? row['{{ $key }}'].toLocaleString() : (row['{{ $key }}'] ?? 0)"
                                ></span>
                            </td>
                        @endif
                    @endforeach
                </tr>
            </template>
        </tbody>
    </table>
</div>
