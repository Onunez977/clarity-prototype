<?php

namespace App\Filament\Pages;

use App\Facades\Analytics;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\Computed;

class Roles extends Page
{
    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected string $view = 'filament.pages.roles';

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'roles');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('viewAny', Role::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newRole')
                ->label('New Role')
                ->icon('heroicon-o-plus')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->clearValidation();

                    $data['org_id'] = auth()->user()->org_id;

                    try {
                        Validator::make($data, [
                            'org_id' => [
                                'required',
                                'integer',
                            ],
                            'name' => [
                                'required',
                                Rule::unique('roles', 'name')->where(fn ($query) => $query->where('org_id', auth()->user()->org_id)),
                            ],
                        ])->validate();

                        Role::create($data);
                    } catch (\Exception $exception) {
                        Notification::make()
                            ->title('Role not created')
                            ->body('That role has already been created.')
                            ->danger()
                            ->send();
                    }
                })
                ->successNotification(
                    Notification::make()
                        ->title('Role created')
                        ->success()
                )
                ->after(function () {
                    // Refresh computed property.
                    unset($this->roles);
                }),
        ];
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::withoutGlobalScopes()
            ->with('permissions')
            ->whereNull('org_id')
            ->orWhere('org_id', auth()->user()->org_id)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function permissions(): Collection
    {
        return Permission::query()
            ->orderByName()
            ->get();
    }

    public function userRoles(Role $role, bool $count = false): int|Collection
    {
        return $role->users()
            ->where('users.org_id', auth()->user()->org_id)
            ->when(
                $count,
                fn ($query) => $query->count(),
                fn ($query) => $query->get()
            );
    }

    public function deleteRole(int $role_id): void
    {
        $role = Role::find($role_id);

        Gate::authorize('delete', $role);

        if ($role->org_id !== null && $role->org_id === auth()->user()->org_id) {

            // If the role's users only have this role, update to Default role.
            if ($role->users()->count() > 0) {
                $defaultRole = Role::where('name', RoleEnum::USER->value)->first();

                if ($defaultRole) {
                    foreach ($role->users as $user) {
                        if ($user->roles()->count() === 1) {
                            $user->removeRole($role->name);
                            $user->assignRole($defaultRole);
                        }
                    }
                }
            }

            $role->delete();

            Notification::make()
                ->title('Role deleted successfully.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Unable to delete role.')
            ->warning()
            ->send();
    }

    public function removeUserFromRole($role_id, $user_id): void
    {
        $role = Role::find($role_id);

        Gate::authorize('delete', $role);

        $user = User::find($user_id);

        if ($user?->hasRole($role->name)) {
            $user->removeRole($role->name);

            Notification::make()
                ->title('User removed from Role.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Unable to complete action.')
            ->warning()
            ->send();
    }

    public function toggleRolePermission($role_id, $permission_id): void
    {
        $role = Role::find($role_id);
        $permission = Permission::find($permission_id);

        if ($permission && $role?->org_id === auth()->user()->org_id) {
            if ($role->hasPermissionTo($permission->name)) {
                $role->revokePermissionTo($permission->name);

                Notification::make()
                    ->title('Permission removed from Role.')
                    ->success()
                    ->send();
            } else {
                $role->givePermissionTo($permission->name);

                Notification::make()
                    ->title('Permission added to Role.')
                    ->success()
                    ->send();
            }

            // Refresh computed property.
            unset($this->roles);
        } else {
            Notification::make()
                ->title('Unable to complete action.')
                ->warning()
                ->send();
        }
    }
}
