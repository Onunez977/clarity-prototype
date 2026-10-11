<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InviteTypeEnum;
use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class InviteRedemptionController extends Controller
{
    public function show(Request $request): View
    {
        return view('invites.redeem', [
            'email' => (string) $request->query('email', ''),
            'code' => (string) $request->query('code', ''),
        ]);
    }

    public function redeem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $email = \strtolower(\trim($data['email']));
        $code = \strtoupper(\trim($data['code']));

        $invite = Invite::query()
            ->where('type', InviteTypeEnum::CLIENT_SCANS->value)
            ->where('invitable_type', 'org')
            ->whereNotNull('approved_at')
            ->whereNull('accepted_at')
            ->where('meta->to', $email)
            ->where('meta->code', $code)
            ->first();

        if (! $invite) {
            throw ValidationException::withMessages([
                'code' => 'That invite is invalid or has expired. Please double-check the email and code, or contact the sender for a new invite.',
            ]);
        }

        $clientOrg = Org::query()
            ->where('type', OrgType::Client->value)
            ->find($invite->invitable_id);

        if (! $clientOrg) {
            throw ValidationException::withMessages([
                'code' => 'The client account for this invite no longer exists. Please contact the sender.',
            ]);
        }

        $user = DB::transaction(function () use ($email, $clientOrg, $invite) {
            $user = User::query()
                ->where('email', $email)
                ->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $invite->meta['contact_person'] ?? $email,
                    'email' => $email,
                    'org_id' => $clientOrg->id,
                    'password' => bcrypt(Str::random(32)),
                    'email_verified_at' => now(),
                ]);
            } elseif ($user->org_id !== $clientOrg->id) {
                $user->update(['org_id' => $clientOrg->id]);
            }

            setPermissionsTeamId($user->org_id);

            if (! $user->hasRole(RoleEnum::CLIENT->value)) {
                $user->assignRole(RoleEnum::CLIENT->value);
            }

            $invite->accept();

            return $user;
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $repoOrgName = $invite->user?->org?->name;

        Notification::make()
            ->title('Welcome to Insight LPR')
            ->body(
                $repoOrgName
                    ? "You now have access to scan data from {$repoOrgName}. Use the filters above to search by plate."
                    : 'You now have access to scan data. Use the filters above to search by plate.'
            )
            ->success()
            ->duration(8000)
            ->send();

        return redirect()->intended(ClientSearchScansResource::getUrl(panel: 'client'));
    }
}
