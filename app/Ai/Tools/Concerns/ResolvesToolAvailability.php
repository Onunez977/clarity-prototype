<?php

namespace App\Ai\Tools\Concerns;

use App\Ai\PageContext;
use App\Ai\Surface;
use App\Ai\Tools\Contracts\RegisteredTool;

/**
 * Surface/page availability defaults for registered tools.
 *
 * `authorizedFor()` is deliberately NOT defaulted here. It is a required member
 * of {@see RegisteredTool}, so leaving it out forces
 * every tool to state its own permission gate and makes a new tool fail to
 * instantiate rather than silently inherit "everyone". A permissive default is
 * what let the chat search tools serve scan/hit/hotlist records to users the
 * Filament policies denied.
 */
trait ResolvesToolAvailability
{
    public function availableOn(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces(), true);
    }

    public function availableOnPage(?PageContext $page): bool
    {
        return true;
    }

    /**
     * @return Surface[]
     */
    protected function surfaces(): array
    {
        return Surface::cases();
    }
}
