@auth
    <button
        type="button"
        aria-label="What's New"
        onclick="if (window.Beamer) { Beamer.show(); }"
        class="fi-icon-btn relative w-fit flex items-center whitespace-nowrap rounded-lg px-2 py-1.5 text-sm font-medium text-white outline-none transition duration-75 hover:text-primary-600 hover:bg-gray-100 focus-visible:bg-gray-100 dark:text-gray-200 dark:hover:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
    >
        <x-filament::icon
            icon="heroicon-o-megaphone"
            class="h-6 w-6 text-white sm:hidden dark:text-gray-500"
        />
        <span class="hidden sm:inline">What's New</span>
    </button>
@endauth
