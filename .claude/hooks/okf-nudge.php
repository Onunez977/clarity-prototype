#!/usr/bin/env php
<?php

/**
 * Claude Code PostToolUse hook — OKF drift nudge.
 *
 * Non-blocking (always exits 0). After an Edit/Write/MultiEdit lands on a
 * drift-causing surface (app/, config/, database/, tests/), prints a one-line
 * reminder to stdout (which Claude Code surfaces to the model as context) so
 * the agent offers to sync the OKF wiki at the moment drift is introduced.
 *
 * Registered in .claude/settings.json under hooks.PostToolUse.
 *
 * Noise control: one nudge per edited path per 10 minutes (marker file under
 * TMPDIR), so a flurry of edits to the same file doesn't spam the session.
 *
 * Stdin: Claude Code PostToolUse JSON: {"tool_name":"Edit","tool_input":{...}}
 */
$raw = stream_get_contents(STDIN);
$payload = json_decode($raw, true);
if (! is_array($payload)) {
    exit(0); // nothing to do — don't block
}

$toolInput = $payload['tool_input'] ?? [];
// Edit/MultiEdit use file_path; Write uses path.
$path = $toolInput['file_path'] ?? ($toolInput['path'] ?? '');
if ($path === '') {
    exit(0);
}

// Only nudge for drift-causing surfaces.
if (! preg_match('#(^|/)(app|config|database|tests)/#', $path)) {
    exit(0);
}

// Normalize to a repo-relative-ish path for the message.
$rel = $path;
if (($pos = strpos($path, '/app/')) !== false) {
    $rel = substr($path, $pos + 1);
} elseif (($pos = strpos($path, '/config/')) !== false) {
    $rel = substr($path, $pos + 1);
} elseif (($pos = strpos($path, '/database/')) !== false) {
    $rel = substr($path, $pos + 1);
} elseif (($pos = strpos($path, '/tests/')) !== false) {
    $rel = substr($path, $pos + 1);
} elseif (str_starts_with($path, 'app/') || str_starts_with($path, 'config/')
    || str_starts_with($path, 'database/') || str_starts_with($path, 'tests/')) {
    $rel = $path;
}

// 10-minute per-path dedupe so repeated edits don't spam.
$tmpDir = sys_get_temp_dir() ?: '/tmp';
$marker = $tmpDir.'/okf-nudge-'.md5($rel);
if (is_file($marker) && (time() - filemtime($marker)) < 600) {
    exit(0);
}
@touch($marker);

// stdout is surfaced to the model as context by PostToolUse hooks.
fwrite(STDOUT, sprintf(
    "[okf] You edited %s. If that changes documented behavior, keep the wiki in sync — run `update wiki from %s` (see okf/CONTRIBUTING.md). Only nudge once per 10 min per file.\n",
    $rel,
    $rel
));
exit(0);
