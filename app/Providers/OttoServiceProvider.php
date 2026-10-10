<?php

namespace App\Providers;

use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Ai\Tools\HitSearchTool;
use App\Ai\Tools\HotlistSearchTool;
use App\Ai\Tools\KnowledgeBaseSearchTool;
use App\Ai\Tools\Metrics\WidgetMetricTool;
use App\Ai\Tools\ProposeCreateZoneTool;
use App\Ai\Tools\ProposeUpdateZoneTool;
use App\Ai\Tools\ScanSearchTool;
use App\Ai\Tools\ScoutDriverPositionTool;
use App\Ai\Tools\ShowResultDetailsTool;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ZoneSearchTool;
use Illuminate\Support\ServiceProvider;

class OttoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KnowledgeBase::class, fn (): KnowledgeBase => new KnowledgeBase);

        $this->app->singleton(ToolRegistry::class, function (): ToolRegistry {
            return (new ToolRegistry)->registerMany([
                ScanSearchTool::class,
                HitSearchTool::class,
                HotlistSearchTool::class,
                ShowResultDetailsTool::class,
                KnowledgeBaseSearchTool::class,
                ...WidgetMetricTool::registered(),
                ScoutDriverPositionTool::class,
                ZoneSearchTool::class,
                ProposeCreateZoneTool::class,
                ProposeUpdateZoneTool::class,
                ApplyScanSearchFiltersTool::class,
                ApplyHitSearchFiltersTool::class,
                ApplyHotlistSearchFiltersTool::class,
            ]);
        });
    }
}
