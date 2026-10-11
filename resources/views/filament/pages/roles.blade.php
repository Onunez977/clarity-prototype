<x-filament-panels::page x-init="$store.sidebar.close()">
    @forelse($this->roles as $role)
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">
                {{ $role->name }}
            </x-slot>

            <x-slot name="description">
                [Click to <span x-text="isCollapsed ? 'Expand' : 'Close'"></span>]
            </x-slot>

            <x-slot name="afterHeader">
                @can('delete', $role)
                    <x-filament::button
                        size="sm"
                        color="danger"
                        :outlined="true"
                        icon="heroicon-m-trash"
                        tooltip="Delete Role"
                        wire:click="deleteRole('{{ $role->id }}')"
                    />
                @endcan
            </x-slot>

            <div x-data="{ activeTab: 'users' }">
                <x-filament::tabs>
                    <x-filament::tabs.item
                        alpine-active="activeTab === 'users'"
                        x-on:click="activeTab = 'users'"
                    >
                        Users

                        <x-slot name="badge">
                            {{ $this->userRoles($role, count: true) }}
                        </x-slot>
                    </x-filament::tabs.item>

                    <x-filament::tabs.item
                        alpine-active="activeTab === 'permissions'"
                        x-on:click="activeTab = 'permissions'"
                    >
                        Permissions

                        @if ($role->org_id !== null)
                            <x-slot name="badge">
                                {{ $role->permissions->count() }}/{{ $this->permissions->count() }}
                            </x-slot>
                        @else
                            <x-slot name="badge">
                                {{ $role->permissions->count() }}
                            </x-slot>
                        @endif
                    </x-filament::tabs.item>
                </x-filament::tabs>

                <div x-show="activeTab === 'users'" class="flex flex-col mt-4">
                    @forelse($this->userRoles($role) as $user)
                        <x-filament::fieldset class="{{ ! $loop->last ? 'mb-4' : '' }}">
                            <div class="flex justify-between items-center">
                                {{ $user->email }}

                                @can('removeUser', [$role, $user])
                                    <x-filament::button
                                        size="sm"
                                        color="warning"
                                        :outlined="true"
                                        icon="heroicon-m-user-minus"
                                        tooltip="Remove User from Role"
                                        wire:click="removeUserFromRole('{{ $role->id }}', '{{ $user->id }}')"
                                    />
                                @endcan
                            </div>
                        </x-filament::fieldset>
                    @empty
                        <x-filament::fieldset>
                            No users found.
                        </x-filament::fieldset>
                    @endforelse
                </div>

                <div x-show="activeTab === 'permissions'" class="grid grid-cols-1 lg:grid-cols-4 gap-4 mt-4">
                    @if ($role->org_id !== null)
                        @foreach($this->permissions as $permission)
                            <x-filament::fieldset>
                                <button
                                    class="flex flex-col justify-start items-start gap-2 cursor-pointer"
                                    wire:click="toggleRolePermission('{{ $role->id }}', '{{ $permission->id }}')"
                                >
                                    <p class="flex items-center gap-2">
                                        @if ($role->hasPermissionTo($permission->name))
                                            <x-filament::input.checkbox checked />
                                        @else
                                            <x-filament::input.checkbox />
                                        @endif

                                        {{ $permission->title }}
                                    </p>
                                    <small class="text-sm text-left text-gray-500 dark:text-gray-400">
                                        {{ $permission->description }}
                                    </small>
                                </button>
                            </x-filament::fieldset>
                        @endforeach
                    @else
                        @forelse($role->permissions as $permission)
                            <x-filament::fieldset>
                                <div class="flex flex-col justify-start items-start gap-2">
                                    <p>
                                        {{ \InsightLPR\BlackAdam\Enum\PermissionEnum::tryFrom($permission->name)?->title() ?? str($permission->name)->title()->replace('-', ' ') }}
                                    </p>
                                    <small class="text-sm text-left text-gray-500 dark:text-gray-400">
                                        {{ \InsightLPR\BlackAdam\Enum\PermissionEnum::tryFrom($permission->name)?->description() ?? '' }}
                                    </small>
                                </div>
                            </x-filament::fieldset>
                        @empty
                            <x-filament::fieldset>
                                No permissions found.
                            </x-filament::fieldset>
                        @endforelse
                    @endif
                </div>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            No roles.
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
