/**
 * Otto frontend markdown + tool-call stripping (PG-036).
 *
 * Keep `stripToolCalls()` in lockstep with
 * App\Support\Otto\MessageRenderer::stripToolCalls (DB re-render).
 *
 * When the lists drift, text the user never saw while streaming reappears
 * after a reload: leaked <function=…> / <｜…｜> delimiters, a hallucinated
 * <div class="filter-confirmation"> card, or a **Proposed filter changes:** list.
 * Str::markdown(html_input: 'strip') is not a substitute — it drops the tags
 * but keeps their inner text.
 *
 * The function name `stripToolCalls` must stay grep-able for source-contract tests.
 */

const TOOL_CALL_PATTERNS = [
    // Alpine + MessageRenderer
    /<function=[\s\S]*?<\/function>/gi,
    /<tool_call>[\s\S]*?<\/tool_call>/gi,
    /<parameter=[\s\S]*?<\/parameter>/gi,
    /<\/?(?:function|tool_call|parameter)\b[^>]*>/gi,
    /<(?:function|tool_call)\b[\s\S]*$/i,
    // MessageRenderer — GLM/Kimi section delimiters (Alpine does not have these)
    /<\|tool_calls_section_begin\|>[\s\S]*?<\|tool_calls_section_end\|>/gi,
    /<\|tool_calls_section_begin\|>[\s\S]*$/i,
    /<\|tool_call(?:s_section)?_(?:begin|end)\|>|<\|tool_call_argument_begin\|>/gi,
    // GLM/DeepSeek native tool-call delimiters leaked as text (｜ = ｜).
    /<｜DSML｜function_calls/g,
    /<｜[^>\n]*?｜>/g,
    /<｜[A-Za-z0-9_｜]*$/g,
    // Alpine live-stream: leaked internal-reasoning clause
    /(^|[.!?]\s+)The user\b[^.!?]*(?:[.!?]+\s*|$)/g,
    // Kimi K2.5 hallucinated filter-confirmation UI
    /<div\s+class=["']filter-confirmation["'][\s\S]*?<\/div>/gi,
    /<div\s+class=["']filter-confirmation["'][\s\S]*$/i,
    /\n?\*\*Proposed filter changes:\*\*\s*(?:\n[-*+]\s+[^\n]+)+/gi,
    // Model-invented tool-call stand-in
    /\[Calling\s+[^\]]+\]/gi,
];

/**
 * Remove leaked tool-call markup. A still-streaming, unclosed opener and
 * everything after it is hidden until it resolves.
 *
 * @param {string} text
 * @returns {string}
 */
export function stripToolCalls(text) {
    let out = String(text ?? '');
    for (const pattern of TOOL_CALL_PATTERNS) {
        pattern.lastIndex = 0;
        const replacement = pattern.source.includes('The user') ? '$1' : '';
        out = out.replace(pattern, replacement);
    }

    return out.trimEnd();
}

/**
 * True when the remaining prose is only "I'm about to search…" filler.
 * Keep in lockstep with App\Support\Otto\ToolInvocationNarration.
 *
 * @param {string} text
 * @returns {boolean}
 */
export function isToolInvocationNarration(text) {
    const stripped = stripToolCalls(String(text ?? ''))
        .replace(/<!--otto_table:[^>]+-->/g, '')
        .trim();
    if (stripped === '') {
        return true;
    }
    if (stripped.length > 240) {
        return false;
    }

    return /^(?:let me |i(?:'ll| will) )(?:pull up|look up|search|find|get|check)\b/i.test(stripped);
}

const WIDGET_MARKER = /<!--otto_table:(scan|hit|hotlist|scout_driver|zone|convoy):([a-zA-Z0-9]+)-->/g;
const MARKDOWN_TABLE = /\n*\|.*\|.*\n\|[\s\-:|]+\|.*\n(\|.*\|.*\n)*(?:\|.*\|.*)?/gm;

/**
 * @param {string} content
 * @returns {{ text: string, widgets: Array<{ type: string, searchId: string }> }}
 */
export function extractWidgetMarkers(content) {
    WIDGET_MARKER.lastIndex = 0;
    const widgets = [];
    const text = String(content ?? '').replace(WIDGET_MARKER, (_match, type, searchId) => {
        widgets.push({ type, searchId });

        return '';
    });

    return { text, widgets };
}

/**
 * @param {string} s
 * @returns {string}
 */
function ottoEscapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * @param {string} escaped
 * @returns {string}
 */
function ottoRenderInline(escaped) {
    return escaped
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+?)__/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\s][^*]*?)\*/g, '$1<em>$2</em>')
        .replace(/(^|[^_\w])_([^_\s][^_]*?)_/g, '$1<em>$2</em>')
        .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (match, label, href) => {
            const url = safeMarkdownUrl(href);
            if (!url) {
                return match;
            }

            return `<a href="${url}" rel="noopener noreferrer" target="_blank">${label}</a>`;
        });
}

/**
 * @param {string} escapedHref
 * @returns {string|null}
 */
function safeMarkdownUrl(escapedHref) {
    const raw = escapedHref
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"');

    const allowed = /^(https?:|mailto:)/i.test(raw) || (raw.startsWith('/') && !raw.startsWith('//'));
    if (!allowed) {
        return null;
    }

    return ottoEscapeHtml(raw).replace(/"/g, '&quot;');
}

/**
 * Minimal, XSS-safe Markdown → HTML for the subset Otto emits: headings,
 * bold, italic, inline code, fenced code, links, unordered/ordered lists,
 * and blank-line-separated paragraphs. Everything is HTML-escaped BEFORE
 * any markup is introduced. Links allow only http(s), mailto, and same-origin
 * paths.
 *
 * @param {string} src
 * @returns {string}
 */
export function ottoRenderMarkdown(src) {
    const lines = String(src ?? '').replace(/\r\n?/g, '\n').split('\n');
    const blocks = [];
    let index = 0;

    while (index < lines.length) {
        if (lines[index].trimStart().startsWith('```')) {
            const code = [];
            index += 1;
            while (index < lines.length && !lines[index].trimStart().startsWith('```')) {
                code.push(lines[index]);
                index += 1;
            }
            if (index < lines.length) {
                index += 1;
            }
            blocks.push('<pre><code>' + ottoEscapeHtml(code.join('\n')) + '</code></pre>');
            continue;
        }

        const prose = [];
        while (index < lines.length && !lines[index].trimStart().startsWith('```')) {
            prose.push(lines[index]);
            index += 1;
        }
        const rendered = renderProseLines(prose);
        if (rendered !== '') {
            blocks.push(rendered);
        }
    }

    return blocks.join('');
}

/**
 * @param {string[]} lines
 * @returns {string}
 */
function renderProseLines(lines) {
    const blocks = [];
    let para = [];
    let list = null;

    const flushPara = () => {
        if (para.length) {
            blocks.push('<p>' + para.map((l) => ottoRenderInline(ottoEscapeHtml(l))).join(' ') + '</p>');
            para = [];
        }
    };
    const flushList = () => {
        if (list) {
            const items = list.items.map((it) => '<li>' + ottoRenderInline(ottoEscapeHtml(it)) + '</li>').join('');
            blocks.push(`<${list.type}>${items}</${list.type}>`);
            list = null;
        }
    };

    for (const raw of lines) {
        const line = raw.trimEnd();
        if (line.trim() === '') {
            flushPara();
            flushList();
            continue;
        }

        const heading = line.match(/^(#{1,6})\s+(\S.*)$/);
        const ul = line.match(/^\s*[-*+]\s+(.*)$/);
        const ol = line.match(/^\s*\d+\.\s+(.*)$/);

        if (heading) {
            flushPara();
            flushList();
            const level = heading[1].length;
            blocks.push(`<h${level}>${ottoRenderInline(ottoEscapeHtml(heading[2]))}</h${level}>`);
            continue;
        }

        if (ul) {
            flushPara();
            if (!list || list.type !== 'ul') {
                flushList();
                list = { type: 'ul', items: [] };
            }
            list.items.push(ul[1]);
            continue;
        }
        if (ol) {
            flushPara();
            if (!list || list.type !== 'ol') {
                flushList();
                list = { type: 'ol', items: [] };
            }
            list.items.push(ol[1]);
            continue;
        }

        flushList();
        para.push(line);
    }

    flushPara();
    flushList();

    return blocks.join('');
}

/**
 * Prepare assistant content for display: strip tool calls, extract widget
 * markers, drop leaked markdown tables when a widget is present, hide
 * tool-invocation narration.
 *
 * @param {string} content
 * @returns {{ html: string, text: string, widgets: Array<{ type: string, searchId: string }> }}
 */
export function prepareAssistantContent(content) {
    let clean = stripToolCalls(content);
    const extracted = extractWidgetMarkers(clean);
    clean = extracted.text;
    if (extracted.widgets.length > 0) {
        MARKDOWN_TABLE.lastIndex = 0;
        clean = clean.replace(MARKDOWN_TABLE, '');
    }
    if (isToolInvocationNarration(clean)) {
        clean = '';
    }

    const text = clean.trim();

    return {
        html: text === '' ? '' : ottoRenderMarkdown(text),
        text,
        widgets: extracted.widgets,
    };
}
