<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Ai\PageContext;
use App\Ai\Tools\Concerns\ResolvesToolAvailability;
use App\Ai\Tools\Contracts\RegisteredTool;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\WidgetMetric;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One widget metric Otto can call from chat (DEV-2706).
 *
 * Registered for every Otto user. The widget's own gate decides whether the
 * number is returned. These tools are not tied to the screen the user is on.
 */
abstract class WidgetMetricTool implements RegisteredTool
{
    use ResolvesToolAvailability;

    public function __construct(
        protected User $user,
        protected Investigation $investigation,
        protected WidgetMetric $metrics = new WidgetMetric,
    ) {}

    public function authorizedFor(User $user): bool
    {
        return true;
    }

    public function availableOnPage(?PageContext $page): bool
    {
        return true;
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->investigation->toolCallLimitReached()) {
            return 'Tool call limit reached for this message. Summarize what you have.';
        }

        $this->investigation->incrementToolCalls();

        return $this->read($request);
    }

    abstract protected function read(Request $request): string;

    /**
     * @return list<class-string<WidgetMetricTool>>
     */
    public static function registered(): array
    {
        return [
            UniqueScanCountsTool::class,
            UniqueLprCaseClaimsTool::class,
            LprRepossessionsTool::class,
            RecoveryCountsTool::class,
            LiveHitsTool::class,
            HistoricalHitsTool::class,
            StagingLiveHitsTool::class,
            StagingHistoricalHitsTool::class,
            CompanyMissedOpportunitiesTool::class,
            UniqueScansLeaderboardTool::class,
            RecoveryLeaderTool::class,
            StagingLiveHitLeaderTool::class,
            HotlistCountTool::class,
            TotalAssignmentsTool::class,
            TotalRepossessionsTool::class,
            LprAssistsTool::class,
            AverageRecoveryRateTool::class,
            ClientMissedOpportunitiesTool::class,
            CurrentOpenAssignmentsTool::class,
            ThirtyDayRecoveryRateTool::class,
            TotalClientAssignmentsTool::class,
            TotalAcceptedTool::class,
            TotalDeclinedTool::class,
            DaysToRepoTool::class,
            RecoveryStatsTool::class,
        ];
    }
}
