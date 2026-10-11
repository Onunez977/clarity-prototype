<?php

namespace App\Ai\Tools;

use App\Ai\PageContext;
use App\Ai\Surface;
use App\Ai\Tools\Contracts\PageAwareTool;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Models\Investigation;
use App\Models\User;

class ToolRegistry
{
    /**
     * @var array<int, class-string<RegisteredTool>>
     */
    protected array $tools = [];

    /**
     * @param  class-string<RegisteredTool>  $toolClass
     */
    public function register(string $toolClass): static
    {
        if (! in_array($toolClass, $this->tools, true)) {
            $this->tools[] = $toolClass;
        }

        return $this;
    }

    /**
     * @param  array<int, class-string<RegisteredTool>>  $toolClasses
     */
    public function registerMany(array $toolClasses): static
    {
        foreach ($toolClasses as $toolClass) {
            $this->register($toolClass);
        }

        return $this;
    }

    /**
     * @return array<int, class-string<RegisteredTool>>
     */
    public function registered(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, RegisteredTool>
     */
    public function resolve(Surface $surface, User $user, Investigation $investigation, ?PageContext $page = null): array
    {
        $resolved = [];

        foreach ($this->tools as $toolClass) {
            $tool = new $toolClass($user, $investigation);

            if ($tool->availableOn($surface) && $tool->authorizedFor($user) && $tool->availableOnPage($page)) {
                if ($tool instanceof PageAwareTool) {
                    $tool->onPage($page);
                }

                $resolved[] = $tool;
            }
        }

        return $resolved;
    }
}
