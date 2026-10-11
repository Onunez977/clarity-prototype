#!/usr/bin/env php
<?php

/**
 * OKF wiki linter.
 *
 * Health-checks the okf/ knowledge bundle. Exits non-zero on hard errors
 * (broken internal links, missing resource: paths), zero on success.
 * Orphan pages are reported as warnings and do not fail the run.
 *
 * Run locally:   php scripts/okf-lint.php
 * Run in CI:     invoked by .github/workflows/okf-lint.yml
 *
 * Checks:
 *   1. Broken internal Markdown links (relative .md targets that don't exist).
 *   2. resource: frontmatter paths that don't exist in the repo.
 *   3. Orphan concept pages (no inbound links) — warning only.
 *   4. Dangling catalogued-ID references (PG-NNN / ADR-NNN cited but never
 *      defined as a section heading). Also scans the agent-facing docs
 *      (CLAUDE.md, AGENTS.md), because the real-world instance of this defect
 *      was CLAUDE.md citing "platform-gotchas.md PG-021" after that section
 *      was lost in an edit — see okf/log.md (2026-07-06), which recorded the
 *      dangling reference and worked around it by numbering the next entry
 *      PG-022 rather than fixing it.
 *
 * The data-architecture rule ("every table/view mention states its DB") is
 * intentionally NOT auto-checked here — it is too fuzzy to enforce reliably
 * without false positives. It remains a human/agent lint item documented in
 * okf/CONTRIBUTING.md.
 */
$root = dirname(__DIR__);
$okf = $root.'/okf';

if (! is_dir($okf)) {
    fwrite(STDERR, "okf/ bundle not found at {$okf}\n");
    exit(2);
}

$hardErrors = [];
$warnings = [];

/** Recursively collect .md files under a directory. */
$mdFiles = function (string $dir) use (&$mdFiles): array {
    $out = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = "{$dir}/{$entry}";
        if (is_dir($path)) {
            $out = array_merge($out, $mdFiles($path));
        } elseif (str_ends_with($entry, '.md')) {
            $out[] = $path;
        }
    }

    return $out;
};

$files = $mdFiles($okf);

// Build a set of all internal link targets (by path relative to repo root)
// and a map of which files reference which targets (for orphan detection).
$allRefs = []; // target => [source files]

// Anchors strip helper: split "path#anchor" into path part.
$stripAnchor = static fn (string $link) => preg_split('/#/', $link, 2)[0];

foreach ($files as $file) {
    $relFile = substr($file, strlen($root) + 1); // e.g. okf/concepts/hit.md
    $text = file_get_contents($file);

    // Strip code so we don't match links inside fenced blocks or inline
    // code spans (those are illustrative, not real links). Fenced first,
    // then inline `...` spans.
    $text = preg_replace('/```.*?```/s', '', $text) ?? '';
    $text = preg_replace('/`[^`]*`/', '', $text) ?? '';

    // --- Check 2: resource: frontmatter path ---
    if (preg_match('/^---\n(.*?)\n---/s', $text, $fm)) {
        if (preg_match('/^resource:\s*[\'"]?([^\'"\n]+)[\'"]?\s*$/m', $fm[1], $m)) {
            $resource = trim($m[1]);
            if ($resource !== '' && ! file_exists("{$root}/{$resource}")) {
                $hardErrors[] = "{$relFile}: resource path does not exist: {$resource}";
            }
        }
    }

    // --- Check 1: internal Markdown links ---
    // Match [label](target) where target ends in .md (skip http/https/mailto).
    preg_match_all('/\]\(([^)]+)\)/', $text, $linkMatches);
    foreach ($linkMatches[1] as $raw) {
        $target = $stripAnchor(trim($raw));
        if ($target === '') {
            continue;
        }
        if (preg_match('#^(https?:|mailto:|/)#', $target)) {
            continue;
        } // external / absolute
        if (! str_ends_with($target, '.md')) {
            continue;
        } // only check .md links

        $resolved = realpath(dirname($file).'/'.$target);
        if ($resolved === false) {
            $hardErrors[] = "{$relFile}: broken link to `{$target}` (target not found)";

            continue;
        }
        $relResolved = substr($resolved, strlen($root) + 1);
        $allRefs[$relResolved][] = $relFile;
    }
}

// --- Check 3: orphan concept pages (warning only) ---
// A concept page is "orphan" if no other file links to it.
// index.md / log.md / CONTRIBUTING.md are excluded from orphan checks (they're
// navigation/history, not concepts, and are entry points themselves).
$navigationFiles = ['okf/index.md', 'okf/log.md', 'okf/CONTRIBUTING.md', 'okf/concepts/index.md'];
$referencedTargets = array_keys($allRefs);
foreach ($files as $file) {
    $relFile = substr($file, strlen($root) + 1);
    if (in_array($relFile, $navigationFiles, true)) {
        continue;
    }
    if (! in_array($relFile, $referencedTargets, true)) {
        $warnings[] = "{$relFile}: orphan page (no inbound links)";
    }
}

// --- Check 4: dangling catalogued-ID references (PG-NNN / ADR-NNN) ---
// Definitions are section headings ("## PG-021: ..."); references are any other
// mention. A reference with no definition is a hard error — it sends a reader
// (human or agent) to a section that does not exist.
//
// Agent-facing docs are scanned too: the observed failure was CLAUDE.md citing
// a PG id that had been dropped from the wiki, which okf-only scanning misses.
$agentDocs = array_values(array_filter(
    [$root.'/CLAUDE.md', $root.'/AGENTS.md'],
    'is_file'
));

$definedIds = [];
foreach ($files as $file) {
    if (preg_match_all('/^##\s+((?:PG|ADR)-\d+)\b/m', (string) file_get_contents($file), $m)) {
        foreach ($m[1] as $id) {
            $definedIds[$id] = true;
        }
    }
}

foreach (array_merge($files, $agentDocs) as $file) {
    $relFile = substr($file, strlen($root) + 1);
    $contents = (string) file_get_contents($file);

    // Drop heading lines so a definition never counts as its own reference.
    $body = preg_replace('/^##\s+(?:PG|ADR)-\d+\b.*$/m', '', $contents);

    if (! preg_match_all('/\b((?:PG|ADR)-\d+)\b/', (string) $body, $m)) {
        continue;
    }

    foreach (array_unique($m[1]) as $id) {
        if (! isset($definedIds[$id])) {
            $hardErrors[] = "{$relFile}: references {$id}, which is not defined as a section in okf/";
        }
    }
}

// --- Check 5: Laravel Boost guidelines must not be re-inlined into the agent docs ---
// The generated block lives at okf/concepts/laravel-boost-guidelines.md. `boost:install`
// rewrites a <laravel-boost-guidelines> block in place if it finds one, and APPENDS the
// whole block to the end of the file if it does not (vendor/laravel/boost/src/Install/
// GuidelineWriter.php:49-67) — so running it silently re-adds ~336 lines to each of
// CLAUDE.md and AGENTS.md. Fail here so that regression is caught rather than merged.
//
// Uses Boost's own paired-tag regex: a prose mention of the opening tag alone (as the
// agent docs make, when explaining this very rule) is correctly ignored.
$boostPage = 'okf/concepts/laravel-boost-guidelines.md';
if (! is_file($root.'/'.$boostPage)) {
    $hardErrors[] = "{$boostPage}: missing — the extracted Laravel Boost guidelines page should live here";
}
foreach ($agentDocs as $file) {
    $relFile = substr($file, strlen($root) + 1);
    if (preg_match('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', (string) file_get_contents($file))) {
        $hardErrors[] = "{$relFile}: contains an inlined <laravel-boost-guidelines> block — "
            ."boost:install re-added it. Move the content back into {$boostPage} and delete the block here.";
    }
}

// --- Report ---
$exit = 0;

if ($warnings) {
    fwrite(STDOUT, "OKF lint — warnings:\n");
    foreach ($warnings as $w) {
        fwrite(STDOUT, "  ⚠  {$w}\n");
    }
    fwrite(STDOUT, "\n");
}

if ($hardErrors) {
    fwrite(STDERR, "OKF lint — errors:\n");
    foreach ($hardErrors as $e) {
        fwrite(STDERR, "  ✖  {$e}\n");
    }
    fwrite(STDERR, "\n".count($hardErrors).' error(s), '.count($warnings)." warning(s)\n");
    $exit = 1;
} else {
    fwrite(STDOUT, sprintf("OKF lint — passed (%d files, %d warnings)\n", count($files), count($warnings)));
}

exit($exit);
