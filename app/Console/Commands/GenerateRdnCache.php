<?php

namespace App\Console\Commands;

use App\Jobs\Rdn\GetOrgBranches;
use App\Jobs\Rdn\GetOrgHotlistCases;
use App\Jobs\Rdn\GetOrgRdnClients;
use App\Models\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class GenerateRdnCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rdn {org?} {--force : Enrich case info even while services.rdn.case_info_enabled is off}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates org meta for RDN local/global client type distinction.';

    /**
     * --force is the canary path: it lets an operator enrich one or two orgs while
     * services.rdn.case_info_enabled is still off for the fleet, so the flag can ship off
     * and be turned on once the results check out - without a second deploy.
     *
     * It has to be asked for explicitly. An org id alone is a routine single-org run and
     * must keep respecting the kill switch: if an operator disabled it because RDN is in
     * trouble, the next person to run `app:rdn 123` should not silently undo that.
     */
    public function handle(): int
    {
        $forced = (bool) $this->option('force');

        // --force is a canary for one (or a few named) orgs. Without an org id it would
        // push the entire active fleet past the kill switch - the opposite of a canary.
        if ($forced && ! $this->argument('org')) {
            $this->error('--force requires an org id; refusing to force the fleet past the kill switch.');

            return self::FAILURE;
        }

        if ($this->argument('org')) {
            $this->queueBatch(Org::find($this->argument('org')), $forced);
        } else {
            Org::active()->cursor()->each(function ($org) use ($forced) {
                $this->queueBatch($org, $forced);
            });
        }

        return self::SUCCESS;
    }

    /**
     * The case-info dispatcher is delayed rather than pushed alongside its siblings:
     * GetOrgBranches has to land this org's branch rows first or every lookup it fans out
     * is a guaranteed miss. The delay carries a per-org phase on top of that head start so
     * the fleet's dispatchers do not all become available on the batch's first second -
     * see GetOrgHotlistCases::dispatchDelaySeconds().
     */
    private function queueBatch(Org $org, bool $forced = false): void
    {
        Bus::batch([
            new GetOrgBranches(orgId: $org->id),
            new GetOrgRdnClients(orgId: $org->id),
            (new GetOrgHotlistCases(orgId: $org->id, forced: $forced))
                ->delay(GetOrgHotlistCases::dispatchDelaySeconds($org->id)),
        ])
            ->onQueue('{low}')
            ->allowFailures()
            ->name('RDN: '.$org->id)
            ->dispatch();
    }
}
