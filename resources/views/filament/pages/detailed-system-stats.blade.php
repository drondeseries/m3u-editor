<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        {{-- Playlist Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Playlists</h3>
            <p class="text-gray-700 dark:text-gray-300">Total: {{ $totalPlaylists }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Last Sync: {{ $lastPlaylistSync }}</p>
        </div>

        {{-- Group Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Groups</h3>
            <p class="text-gray-700 dark:text-gray-300">Total: {{ $totalGroups }}</p>
        </div>

        {{-- Channel Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Channels</h3>
            <p class="text-gray-700 dark:text-gray-300">Total: {{ $totalChannels }}</p>
            <p class="text-gray-700 dark:text-gray-300">Enabled: {{ $enabledChannels }}</p>
        </div>

        {{-- EPG Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">EPGs</h3>
            <p class="text-gray-700 dark:text-gray-300">Total: {{ $totalEpgs }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Last Sync: {{ $lastEpgSync }}</p>
        </div>

        {{-- EPG Channel Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">EPG Channels</h3>
            <p class="text-gray-700 dark:text-gray-300">Total: {{ $totalEpgChannels }}</p>
        </div>
        
        {{-- Mapped EPG Channels Stats --}}
        <div class="p-4 bg-white rounded-lg shadow dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">EPG Mapped Channels</h3>
            <p class="text-gray-700 dark:text-gray-300">Total Mapped to Channels: {{ $mappedEpgChannels }}</p>
        </div>
    </div>
</x-filament-panels::page>
