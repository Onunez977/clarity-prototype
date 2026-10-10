<?php

namespace App\Jobs;

use App\Models\Kit;
use App\Models\Org;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ShareOrgKits implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $sharingUser,
        public User $sharedWithUser,
        public bool $unshare = false,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        foreach ($this->sharingUser->getUserKits(withDeleted: false)->pluck('id') as $kitId) {
            if ($this->unshare) {
                Shared::query()
                    ->where('shared_type', Kit::class)
                    ->where('shared_id', $kitId)
                    ->where('shared_with_type', Org::class)
                    ->where('shared_with_id', $this->sharedWithUser->org_id)
                    ->delete();
            } else {
                Shared::create([
                    'shared_type' => Kit::class,
                    'shared_id' => $kitId,
                    'shared_with_type' => Org::class,
                    'shared_with_id' => $this->sharedWithUser->org_id,
                ]);
            }

        }

        // Bust cache once - the key does not vary per kit.
        Kit::forgetCachedKits((int) $this->sharedWithUser->org_id);
    }
}
