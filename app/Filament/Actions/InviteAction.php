<?php

namespace App\Filament\Actions;

use App\Enums\FeatureEnum;
use App\Enums\InviteTypeEnum;
use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Mail\Invites\NewInvite;
use App\Models\Invite;
use App\Models\Org;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class InviteAction extends Action
{
    protected ?string $name = 'send-invite';

    protected string|Htmlable|Closure|null $label = 'Send an Invite';

    protected function setUp(): void
    {
        parent::setUp();

        $this->isVisible = (Feature::active(FeatureEnum::INVITES->slug()) || session()->has('admin_impersonator'))
            && (auth()->user()?->hasRole(RoleEnum::ADMIN->value) ?? false);

        $this->extraAttributes(trackAttr('invites', 'send-button'));

        $this->setUpForm();
        $this->setUpAction();
    }

    public static function getDefaultName(): ?string
    {
        return 'send-invite';
    }

    public function setUpForm(): void
    {
        $this->schema([
            Forms\Components\Select::make('type')
                ->label('Invite Type')
                ->required()
                ->live()
                ->options(InviteTypeEnum::formOptions()),

            Forms\Components\TextInput::make('to')
                ->label('Recipient Email')
                ->required()
                ->rules(function (Get $get): array {
                    $rules = [
                        'email',
                    ];

                    if ($get('type') === InviteTypeEnum::SHARE_SCANS->value) {
                        $rules[] = 'exists:users,email';
                    }

                    if ($get('type') === InviteTypeEnum::CLIENT_SCANS->value) {
                        $rules[] = function (string $attribute, $value, Closure $fail) {
                            $user = User::query()->where('email', $value)->first();

                            if (! $user) {
                                return;
                            }

                            $clientOrgIds = Org::query()
                                ->where('type', OrgType::Client->value)
                                ->pluck('id');

                            if (
                                $user->hasRole(RoleEnum::CLIENT->value)
                                && $clientOrgIds->isNotEmpty()
                                && ! $clientOrgIds->contains($user->org_id)
                            ) {
                                $fail('A user with that email is already a client of another organization.');
                            }
                        };
                    }

                    return $rules;
                })
                ->validationMessages([
                    'exists' => 'A user with that email does not exist.',
                ]),

            Components\Grid::make(columns: 1)
                ->visible(fn (Get $get) => $get('type') === InviteTypeEnum::CLIENT_SCANS->value)
                ->schema([
                    Forms\Components\Select::make('client_org_id')
                        ->label('Client')
                        ->searchable()
                        ->preload()
                        ->optionsLimit(250)
                        ->helperText('Pick an existing client. Don\'t see them? Leave blank and fill in the new client name below.')
                        ->options(fn () => auth()->user()?->org
                            ?->clientOrgsWithAccess()
                            ->orderBy('name')
                            ->pluck('orgs.name', 'orgs.id')
                            ->toArray() ?? []
                        )
                        ->rules([
                            fn (): array => [
                                function (string $attribute, $value, Closure $fail) {
                                    if (empty($value)) {
                                        return;
                                    }

                                    $clientOrg = Org::query()
                                        ->where('type', OrgType::Client->value)
                                        ->find($value);

                                    if ($clientOrg && ! $clientOrg->hasRdnAccount()) {
                                        $fail('This client doesn\'t have an RDN account configured. Configure RDN on the org before inviting.');
                                    }
                                },
                            ],
                        ]),

                    Forms\Components\TextInput::make('client_name')
                        ->label('New Client Name')
                        ->helperText('Required when creating a new client.')
                        ->required(fn (Get $get) => empty($get('client_org_id')) && $get('type') === InviteTypeEnum::CLIENT_SCANS->value),

                    Forms\Components\TextInput::make('contact_person')
                        ->label('Contact Person')
                        ->required(fn (Get $get) => $get('type') === InviteTypeEnum::CLIENT_SCANS->value),

                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Contact Phone Number')
                        ->required(fn (Get $get) => $get('type') === InviteTypeEnum::CLIENT_SCANS->value),
                ]),

            Forms\Components\Toggle::make('send_email')
                ->label('Send The Recipient An Invite Email?')
                ->inline()
                ->visible(fn (Get $get) => $get('type') !== null
                    && $get('type') !== InviteTypeEnum::CLIENT_SCANS->value),

            Components\Text::make(
                new HtmlString('A 6-digit code will be generated and will need to be entered by the recipient to accept the invite.<br/>The invite will auto-expire after 24 hours if not accepted.')
            )
                ->visible(fn (Get $get) => $get('type') !== null
                    && $get('type') !== InviteTypeEnum::CLIENT_SCANS->value),

            Components\Text::make(
                new HtmlString('A 6-digit code will be generated and emailed to the recipient once the invite is approved in Backyard.<br/>The recipient will then have 7 days to redeem it.')
            )
                ->visible(fn (Get $get) => $get('type') === InviteTypeEnum::CLIENT_SCANS->value),
        ]);
    }

    public function setUpAction(): void
    {
        $this->action(function (array $data) {
            $properties = [
                'code' => \strtoupper(Str::random(6)),
                'to' => \strtolower($data['to']),
            ];

            $autoAcceptedAt = null;
            $expiresAt = now()->addDay();

            $invitable_type = null;
            $invitable_id = null;

            if ($data['type'] === InviteTypeEnum::SHARE_SCANS->value) {
                $recipient = User::query()
                    ->where('email', $data['to'])
                    ->first();

                $invitable_type = 'user';
                $invitable_id = $recipient->id;
            }

            if ($data['type'] === InviteTypeEnum::CLIENT_SCANS->value) {
                $properties['contact_person'] = $data['contact_person'];
                $properties['contact_phone'] = $data['contact_phone'];

                $clientOrg = ! empty($data['client_org_id'])
                    ? Org::query()->where('type', OrgType::Client->value)->find($data['client_org_id'])
                    : Org::query()->create([
                        'name' => $data['client_name'],
                        'type' => OrgType::Client->value,
                    ]);

                $properties['client'] = $clientOrg->name;

                $invitable_type = 'org';
                $invitable_id = $clientOrg->id;
            }

            $isClientScans = $data['type'] === InviteTypeEnum::CLIENT_SCANS->value;

            // CLIENT_SCANS invites are gated by Jon's approval in Backyard:
            // expires_at and the recipient email are set later, when the
            // scheduled command picks up the approval.
            $invite = Invite::create([
                'user_id' => auth()->id(),
                'invitable_type' => $invitable_type,
                'invitable_id' => $invitable_id,
                'type' => $data['type'],
                'meta' => $properties,
                'expires_at' => $isClientScans ? null : $expiresAt,
                'accepted_at' => $autoAcceptedAt,
                'approved_at' => null,
            ]);

            activity('invite')
                ->performedOn($invite)
                ->causedBy(auth()->user())
                ->withProperties(\array_merge([
                    'to' => $data['to'],
                    'type' => $data['type'],
                    'expires_at' => $invite->expires_at,
                ], $properties))
                ->log($data['type'].' Invite');

            Notification::make()
                ->title('Invite Sent')
                ->success()
                ->send();

            // DB Notification to recipient
            if ($invitable_type === 'user' && $invite->invitable) {
                $panel = ($invite->invitable->hasExactRoles([RoleEnum::CLIENT->value]) ?? false)
                    ? 'client'
                    : 'app';

                Notification::make()
                    ->title('You have received an Invite')
                    ->icon('heroicon-m-flag')
                    ->body('You have been invited by '.auth()->user()->email.' to "'.$data['type'].'."')
                    ->actions([
                        Action::make('view')
                            ->url(ListInvites::getUrl(panel: $panel).'?tableFilters[filters][direction]=received')
                            ->button(),
                    ])
                    ->sendToDatabase($invite->invitable);
            }

            // Send email to recipient. CLIENT_SCANS invites are held until
            // Jon approves them in Backyard; SendApprovedClientInviteMails
            // queues the mail then.
            if ((bool) ($data['send_email'] ?? false) && ! $isClientScans) {
                Mail::to($data['to'])->queue(new NewInvite($invite));
            }
        });
    }
}
