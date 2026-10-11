<?php

namespace App\Ai\Tools\Contracts;

use App\Ai\PageContext;

/**
 * A tool whose description adapts to the page the request came from. The registry
 * hands it the current page context after construction so description() can steer
 * the model differently when the page exposes an equivalent capability.
 */
interface PageAwareTool
{
    /**
     * Receive the page context for the current request (null when the page has no
     * registered context). Returns $this for fluent use by the registry.
     */
    public function onPage(?PageContext $page): static;
}
