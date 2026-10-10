<?php

namespace App\Ai\Tools\Contracts;

use App\Ai\PageContext;
use App\Ai\Surface;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;

interface RegisteredTool extends Tool
{
    public function availableOn(Surface $surface): bool;

    public function authorizedFor(User $user): bool;

    public function availableOnPage(?PageContext $page): bool;
}
