<?php

namespace App\Observers;

use App\Enums\InviteTypeEnum;
use App\Jobs\ShareOrgKits;
use App\Mail\Invites\AcceptedInvite;
use App\Mail\Invites\DeclinedInvite;
use App\Models\ClientOrgAccess;
use App\Models\Invite;
use App\Models\Org;
use Illuminate\Support\Facades\Mail;

class InviteObserver
{
    /**
     * Handle the Invite "updating" event.
     */
    public function updating(Invite $invite): void
    {
        $changed = $invite->getDirty();

        if (isset($changed['accepted_at'])) {
            // Send confirmation email to original sender.
            Mail::to($invite->user->email)->queue(new AcceptedInvite($invite));

            if ($invite->type === InviteTypeEnum::SHARE_SCANS->value) {
                // Share the sender's Org Kits with the invitee.
                ShareOrgKits::dispatch(
                    sharingUser: $invite->user,
                    sharedWithUser: $invite->invitable,
                );
            }

            if ($invite->type === InviteTypeEnum::CLIENT_SCANS->value) {
                $repoOrgId = $invite->user?->org_id;
                $clientOrgId = $invite->invitable_id;

                if ($repoOrgId && $clientOrgId) {
                    ClientOrgAccess::withRevoked()->updateOrCreate(
                        [
                            'client_org_id' => $clientOrgId,
                            'repo_org_id' => $repoOrgId,
                        ],
                        [
                            'granted_by_user_id' => $invite->user_id,
                            'granted_at' => now(),
                            'revoked_at' => null,
                        ],
                    );

                    // Ensure the client Org has the client portal enabled so the
                    // invitee can actually access the panel post-redemption.
                    $clientOrg = Org::find($clientOrgId);
                    if ($clientOrg) {
                        $meta = $clientOrg->meta()->firstOrCreate([], ['properties' => []]);
                        $meta->updateProperties(['enable_client_portal' => true]);
                    }
                }
            }
        }

        if (isset($changed['expires_at'])) {
            // Send decline email to original sender.
            Mail::to($invite->user->email)->queue(new DeclinedInvite($invite));
        }
    }
}
