<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

trait HasRetryQuery
{
    public int $retryCount = 0;

    public int $retryUntil = 2;

    public string $lastFailureKey = '';

    public function getTableRecords(): \Illuminate\Contracts\Pagination\CursorPaginator|Paginator|Collection
    {
        try {
            $this->getTable()?->poll(null);

            $records = parent::getTableRecords();

            // A search that succeeds ends the episode. Without this, the same search
            // failing again later matches the stale key and is never reported.
            $this->lastFailureKey = '';
            $this->retryCount = 0;

            return $records;
        } catch (QueryException $ex) {
            // One report per user-initiated search, not per retry: the log channel and the
            // metric are both read as distinct-failure counts, and retrying re-enters here.
            $failureKey = $this->failureKey();
            $isNewFailure = $failureKey !== $this->lastFailureKey;

            if ($isNewFailure) {
                $this->lastFailureKey = $failureKey;
                $this->retryCount = 0;
            }

            $this->retryCount++;

            if (config('app.debug')) {
                throw $ex;
            } elseif ($isNewFailure) {
                Log::channel('db-error')
                    ->error('Query Exception', [
                        'user' => auth()->user()->email,
                        'message' => $ex->getMessage(),
                        'sql' => $ex->getSql(),
                        'connection' => $ex->getConnectionName(),
                        'bindings' => $ex->getBindings(),
                        'code' => $ex->getCode(),
                        'file' => $ex->getFile(),
                        'line' => $ex->getLine(),
                    ]);

                logDatadogCount('clarity.query_timeout', 1, [
                    'category' => 'database_timeout',
                    'item' => 'search_list_query_timeout',
                ]);
            }

            // Set the empty state
            $this->getTable()?->emptyStateIcon('heroicon-o-information-circle');
            $this->getTable()?->emptyStateHeading('Search Criteria is too broad');
            $this->getTable()?->emptyStateDescription('Add additional search criteria to narrow down the search results');

            // Attempt to retry every 10 seconds.
            $this->getTable()?->poll('10s');

            if ($this->retryCount >= $this->retryUntil) {
                // Fail completely
                $this->getTable()?->poll(null);
            } else {
                // Attempt to retry again in 10 seconds.
                $this->getTable()?->poll('10s');
            }

            // Handle the exception
            $setRecordLocales = fn (Collection|Paginator|CursorPaginator $records): Collection|Paginator|CursorPaginator => $records;

            return $setRecordLocales($this->cachedTableRecords = $this->hydratePivotRelationForTableRecords(
                new CursorPaginator(
                    items: collect([]),
                    perPage: $this->getTableRecordsPerPage(),
                    cursor: null,
                    options: [
                        'path' => request()->url(),
                        'pageName' => 'cursor',
                    ]
                )
            ));
        } catch (\Exception $exception) {
            if (config('app.debug')) {
                throw $exception;
            } else {
                Log::channel('db-error')
                    ->error('Query Exception\'s Exception', [
                        'user' => auth()->user()->email,
                        'message' => $exception->getMessage(),
                        'code' => $exception->getCode(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ]);
            }

            $this->getTable()?->poll(null);

            return $this->cachedTableRecords = $this->hydratePivotRelationForTableRecords(
                new CursorPaginator(
                    items: collect([]),
                    perPage: $this->getTableRecordsPerPage(),
                    cursor: null,
                    options: [
                        'path' => request()->url(),
                        'pageName' => 'cursor',
                    ]
                )
            );
        }
    }

    /**
     * Identifies the search being retried. A retry re-enters getTableRecords() with the
     * same filter state, so the key is stable across a retry loop and changes as soon as
     * the user searches for something else.
     */
    protected function failureKey(): string
    {
        // Filter identity ONLY. This key gates the retry budget as well as the report,
        // so anything that can differ between two attempts at the SAME search - the
        // SQLSTATE, Livewire pagination state - makes retries unbounded.
        return md5(serialize([
            data_get($this, 'tableFilters'),
            data_get($this, 'tableDeferredFilters'),
            data_get($this, 'tableSearch'),
            data_get($this, 'tableColumnSearches'),
            data_get($this, 'tableSortColumn'),
            data_get($this, 'tableSortDirection'),
        ]));
    }
}
