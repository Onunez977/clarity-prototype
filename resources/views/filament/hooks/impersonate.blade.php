@if (session()->has('impersonator') && auth()->check())
    @if (session()->has('admin_impersonator'))
        <div class="bg-amber-100 text-amber-700 px-4 py-1.5 relative flex flex-col lg:flex-row justify-center items-center gap-2 lg:space-x-10" role="alert">
            <div class="font-bold">ADMIN MODE: {{ str(data_get(session('impersonator', ['name' => 'N/A']), 'name'))->upper() }} impersonating {{ auth()->user()->name }}</div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('impersonate.exit-admin') }}">
                    @csrf
                    <x-filament::button type="submit" color="warning">Exit Admin Mode</x-filament::button>
                </form>
                <a href="{{ route('impersonate.leave') }}">
                    <x-filament::button>Leave</x-filament::button>
                </a>
            </div>
        </div>
    @else
        <div class="bg-blue-100 text-blue-700 px-4 py-1.5 relative flex flex-col lg:flex-row justify-center items-center gap-2 lg:space-x-10" role="alert">
            <div class="font-bold">{{ str(data_get(session('impersonator', ['name' => 'N/A']), 'name'))->upper() }} you are impersonating {{ auth()->user()->name }}</div>
            <a href="{{ route('impersonate.leave') }}">
                <x-filament::button>Leave</x-filament::button>
            </a>
        </div>
    @endif
@endif
