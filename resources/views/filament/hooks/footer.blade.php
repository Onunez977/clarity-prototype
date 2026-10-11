<a
    href="{{ \App\Filament\Pages\RecentChanges::getUrl(panel: 'app') }}" wire:navigate
    class="flex items-center justify-center fi-header-subheading mt-2 mb-4 text-xs md:text-sm text-gray-600 dark:text-gray-400 max-w-full w-full"
>
    Clarity v{{ \Composer\InstalledVersions::getPrettyVersion('insight-lpr/platform') ?? '2.0.0' }} - Insight LPR
</a>
