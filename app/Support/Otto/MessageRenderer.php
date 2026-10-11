<?php

namespace App\Support\Otto;

use Illuminate\Support\Str;

class MessageRenderer
{
    public static function render(string $content, bool $canAddToReport = false): string
    {
        $content = static::stripToolCalls($content);
        $content = static::stripLeakedReasoning($content);
        $content = ToolInvocationNarration::withoutNarration($content);

        $markers = [];
        $content = preg_replace_callback(
            '/<!--otto_table:(scan|hit|hotlist|scout_driver|zone):([a-zA-Z0-9]+)-->/',
            function ($matches) use (&$markers) {
                $key = '%%OTTO_TABLE_'.count($markers).'%%';
                $markers[$key] = ['type' => $matches[1], 'searchId' => $matches[2]];

                return $key;
            },
            $content
        );

        if (! empty($markers)) {
            $content = preg_replace('/\n*\|.*\|.*\n\|[\s\-:|]+\|.*\n(\|.*\|.*\n)*/m', '', $content);
        }

        $html = Str::markdown($content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        foreach ($markers as $key => $data) {
            $widget = static::renderTable($data['type'], $data['searchId'], $canAddToReport);
            $html = str_replace("<p>{$key}</p>", $widget, $html);
            $html = str_replace($key, $widget, $html);
        }

        return $html;
    }

    /**
     * Keep in lockstep with `stripToolCalls()` in
     * resources/js/otto/lib/markdown.js. The JS strips the live stream; this
     * strips the same message when it is re-rendered from the database. If the
     * lists drift, text the user never saw while streaming reappears after a
     * reload.
     */
    protected static function stripToolCalls(string $content): string
    {
        $patterns = [
            '/<function=[\s\S]*?<\/function>/i',
            '/<tool_call>[\s\S]*?<\/tool_call>/i',
            '/<parameter=[\s\S]*?<\/parameter>/i',
            '/<\/?(?:function|tool_call|parameter)\b[^>]*>/i',
            '/<(?:function|tool_call)\b[\s\S]*$/i',
            '/<\|tool_calls_section_begin\|>[\s\S]*?<\|tool_calls_section_end\|>/i',
            '/<\|tool_calls_section_begin\|>[\s\S]*$/i',
            '/<\|tool_call(?:s_section)?_(?:begin|end)\|>|<\|tool_call_argument_begin\|>/i',
            // GLM/DeepSeek native tool-call delimiters leaked as text (｜ = ｜).
            '/<｜DSML｜function_calls/u',
            '/<｜[^>\n]*?｜>/u',
            '/<｜[A-Za-z0-9_｜]*$/u',
            // Kimi K2.5 occasionally hallucinates a fake filter-confirmation UI
            // even though the real card is rendered from the page_action event.
            // Str::markdown(html_input: 'strip') would drop the tags but keep the
            // inner text, leaving the user a duplicate, non-functional card.
            '/<div\s+class=["\']filter-confirmation["\'][\s\S]*?<\/div>/i',
            '/<div\s+class=["\']filter-confirmation["\'][\s\S]*$/i',
            '/\n?\*\*Proposed filter changes:\*\*\s*(?:\n[-*+]\s+[^\n]+)+/i',
            '/\[Calling\s+[^\]]+\]/i',
        ];

        return trim((string) preg_replace($patterns, '', $content));
    }

    protected static function stripLeakedReasoning(string $content): string
    {
        return trim((string) preg_replace(
            '/(^|[.!?]\s+)The user\b[^.!?]*(?:[.!?]+\s*|$)/',
            '$1',
            $content
        ));
    }

    protected static function renderTable(string $type, string $searchId, bool $canAddToReport): string
    {
        if ($type === 'scout_driver') {
            return view('otto.driver-map-widget', [
                'type' => $type,
                'searchId' => $searchId,
            ])->render();
        }

        return view('otto.search-widget', [
            'type' => $type,
            'searchId' => $searchId,
            'canAddToReport' => $canAddToReport,
        ])->render();
    }
}
