<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the concept-prototype badge to full HTML documents.
 * Fragments (Livewire updates, JSON) are left alone.
 */
class PrototypeBadge
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        if (str_contains($content, 'id="clarity-prototype-badge"')) {
            return $response;
        }

        $badge = <<<'HTML'
<div id="clarity-prototype-badge" style="position:fixed;bottom:12px;right:12px;z-index:2147483646;background:#111827;color:#f9fafb;font:600 12px/1.2 ui-sans-serif,system-ui,sans-serif;padding:6px 10px;border-radius:999px;opacity:.88;pointer-events:none;letter-spacing:.01em;box-shadow:0 1px 3px rgba(0,0,0,.28);">Concept prototype – sample data</div>
HTML;

        $response->setContent(str_replace('</body>', $badge.'</body>', $content));

        return $response;
    }
}
