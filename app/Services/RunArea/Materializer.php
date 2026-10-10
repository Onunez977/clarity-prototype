<?php

declare(strict_types=1);

namespace App\Services\RunArea;

use App\Models\OrgRunArea;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Materializer
{
    private const SOURCE_VINTAGE = 'tiger:2019';

    /** kind => [table, reference column, geometry column] — mirrors pieceFor(). */
    private const REFERENCE_SOURCES = [
        'state' => ['tiger_data.state_all', 'stusps', 'simplified_geom'],
        'msa' => ['tiger_data.tl_2019_us_county', 'cbsafp', 'geom'],
    ];

    public static function materialize(OrgRunArea $runArea): void
    {
        $inputs = $runArea->inputs ?? [];

        if ($inputs === []) {
            return;
        }

        $pieces = [];
        $bindings = [];
        $malformed = [];

        foreach ($inputs as $index => $entry) {
            $piece = self::pieceFor($entry, $bindings);

            if ($piece !== null) {
                $pieces[] = $piece;

                continue;
            }

            // A RECOGNIZED kind that yields no piece (a reference that isn't a non-empty
            // string, or `geometry` supplied as a JSON string rather than an array)
            // contributes nothing to the union. Unrecognized kinds stay a silent skip —
            // that is the documented defensive behaviour.
            if (isset(self::REFERENCE_SOURCES[$entry['kind'] ?? null]) || ($entry['kind'] ?? null) === 'polygon') {
                $malformed[] = ($entry['kind'] ?? '?').' at index '.$index;
            }
        }

        // Before the no-pieces return: entries that were recognized but produced nothing
        // must never be discarded here. A uniform serialization mistake drops EVERY entry,
        // so this is the likelier shape, not the exotic one — and returning silently would
        // leave a previously-materialized row holding its old geometry and old
        // materialized_at while the operator's boundary change is ignored without a signal.
        if ($malformed !== []) {
            throw new RuntimeException(
                "Run area {$runArea->id} has inputs that produced no geometry ("
                .implode(', ', $malformed)
                .'); refusing to materialize.'
            );
        }

        if ($pieces === []) {
            return;
        }

        $unionExpr = count($pieces) === 1
            ? $pieces[0]
            : 'public.ST_Union(ARRAY['.implode(',', $pieces).'])';

        // ST_Union over ARRAY[...] DROPS NULL members, so a state/MSA reference matching
        // no TIGER row still yields a non-empty geometry whenever another input resolves.
        // Emptiness alone would therefore accept a run area that silently excludes a whole
        // state, which then under-clips every Scout heatmap with no signal at all.
        // Resolved outside the transaction so a lookup failure cannot poison it.
        $unresolved = [...self::unresolvedReferences($inputs), ...$malformed];

        // Recognized inputs that resolve to nothing yield a NULL union. Without this guard
        // the row is stamped as materialized over a NULL geometry, and a re-materialize
        // silently replaces a previously-good boundary with NULL. Roll back instead, so
        // the stamp is not applied and the prior geometry survives.
        DB::connection('platform')->transaction(function () use ($unionExpr, $bindings, $runArea, $unresolved): void {
            DB::connection('platform')->update(
                "UPDATE org_run_areas SET
                    geometry = public.ST_Multi(public.ST_CollectionExtract(public.ST_MakeValid($unionExpr), 3)),
                    source_vintage = ?,
                    materialized_at = NOW()
                 WHERE id = ?",
                [...$bindings, self::SOURCE_VINTAGE, $runArea->id]
            );

            $isEmpty = DB::connection('platform')
                ->table('org_run_areas')
                ->where('id', $runArea->id)
                ->rawValue('(geometry IS NULL OR public.ST_IsEmpty(geometry))::int AS is_empty');

            if ((int) $isEmpty === 1) {
                throw new RuntimeException(
                    "Run area {$runArea->id} inputs resolved to an empty geometry; refusing to mark it materialized."
                );
            }

            if ($unresolved !== []) {
                throw new RuntimeException(
                    "Run area {$runArea->id} has inputs that match no TIGER boundary ("
                    .implode(', ', $unresolved)
                    .'); refusing to mark it materialized over a partial area.'
                );
            }
        });
    }

    /**
     * Recognized inputs whose reference matches no usable TIGER boundary, labelled
     * `kind:reference`. Polygon inputs are inline geometry and always "resolve".
     *
     * @param  list<array<string, mixed>>  $inputs
     * @return list<string>
     */
    private static function unresolvedReferences(array $inputs): array
    {
        $wanted = ['state' => [], 'msa' => []];

        foreach ($inputs as $entry) {
            $kind = $entry['kind'] ?? null;
            $reference = $entry['reference'] ?? null;

            if (! is_string($reference) || $reference === '' || ! isset($wanted[$kind])) {
                continue;
            }

            $wanted[$kind][] = $kind === 'state' ? strtoupper($reference) : $reference;
        }

        $unresolved = [];

        foreach (self::REFERENCE_SOURCES as $kind => [$table, $column, $geometry]) {
            $references = array_values(array_unique($wanted[$kind]));

            if ($references === []) {
                continue;
            }

            try {
                $matched = DB::connection('platform')
                    ->table($table)
                    ->whereIn($column, $references)
                    ->whereNotNull($geometry)
                    ->distinct()
                    ->pluck($column)
                    ->all();
            } catch (Throwable) {
                // Degrade for THIS kind only — never discard findings already collected.
                continue;
            }

            foreach (array_diff($references, array_map('strval', $matched)) as $missing) {
                $unresolved[] = "{$kind}:{$missing}";
            }
        }

        return $unresolved;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<mixed>  $bindings
     */
    private static function pieceFor(array $entry, array &$bindings): ?string
    {
        return match ($entry['kind'] ?? null) {
            'state' => self::statePiece($entry, $bindings),
            'msa' => self::msaPiece($entry, $bindings),
            'polygon' => self::polygonPiece($entry, $bindings),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<mixed>  $bindings
     */
    private static function statePiece(array $entry, array &$bindings): ?string
    {
        $reference = $entry['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $bindings[] = strtoupper($reference);

        // tiger_data.state_all is SRID 4269; transform to 4326 to match zones.geom.
        return '(SELECT public.ST_Transform(public.ST_Union(simplified_geom), 4326)
                 FROM tiger_data.state_all WHERE stusps = ?)';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<mixed>  $bindings
     */
    private static function msaPiece(array $entry, array &$bindings): ?string
    {
        $reference = $entry['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $bindings[] = $reference;

        // CBSA polygons = union of member counties; tl_2019_us_county.geom is SRID 4269.
        return '(SELECT public.ST_Transform(public.ST_Union(geom), 4326)
                 FROM tiger_data.tl_2019_us_county WHERE cbsafp = ?)';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<mixed>  $bindings
     */
    private static function polygonPiece(array $entry, array &$bindings): ?string
    {
        $geometry = $entry['geometry'] ?? null;

        if (! is_array($geometry)) {
            return null;
        }

        $bindings[] = json_encode($geometry);

        // Pin SRID 4326 — ST_GeomFromGeoJSON may return SRID 0 if no CRS member.
        return 'public.ST_SetSRID(public.ST_GeomFromGeoJSON(?), 4326)';
    }
}
