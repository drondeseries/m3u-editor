<x-filament-panels::page>
    @forelse ($activeStreamDetails as $stream)
        <div class="mb-4 rounded-lg bg-white p-6 shadow dark:bg-gray-800 dark:border dark:border-gray-700">
            <h3 class="mb-2 text-xl font-semibold text-gray-900 dark:text-white">{{ $stream['title'] }}</h3>
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <p><strong>Owner:</strong> {{ $stream['owner_name'] }}</p>
                <p><strong>Client IP:</strong> {{ $stream['client_ip'] }}</p>
                <p>
                    <strong>Stream URL:</strong> 
                    <a href="{{ $stream['proxy_url'] }}" 
                       target="_blank" 
                       class="text-primary-600 hover:underline dark:text-primary-500">
                       {{ $stream['proxy_url'] }}
                    </a>
                </p>
                <p><em>(Channel ID: {{ $stream['channel_id'] }})</em></p> {{-- Optional: for debugging or future use --}}
            </div>

            <div class="mt-4 border-t pt-4 dark:border-gray-700"> {{-- Added a div for better separation --}}
                <h4 class="mb-2 text-md font-semibold text-gray-700 dark:text-gray-300">Live Statistics:</h4>
                <livewire:stream-stats-chart :streamId="$stream['channel_id']" :key="'chart-' . $stream['channel_id']" />
            </div>

            <div class="mt-4">
                <x-filament::button wire:click="loadAndShowFfprobeStats({{ $stream['channel_id'] }})">
                    View Stream Details
                </x-filament::button>
            </div>
        </div>
    @empty
        <div class="rounded-lg bg-white p-6 text-center shadow dark:bg-gray-800">
            <p class="text-gray-500 dark:text-gray-400">No active streams found.</p>
        </div>
    @endforelse

    {{-- Modal for FFprobe Stream Details --}}
    @if ($showFfprobeDetailsModal)
        <x-filament::modal 
            id="ffprobe-details-modal" 
            wire:model.live="showFfprobeDetailsModal"
            width="2xl">
            
            <x-slot name="heading">
                {{ $ffprobeModalTitle }}
            </x-slot>

            <div class="space-y-4">
                @if ($ffprobeStreamDetails)
                    @forelse ($ffprobeStreamDetails as $index => $detail)
                        @if (isset($detail['error']))
                            <p class="text-danger-500">{{ $detail['error'] }}</p>
                            @break {{-- Stop further processing if it's an error message --}}
                        @elseif (isset($detail['message']))
                            <p>{{ $detail['message'] }}</p>
                            @break {{-- Stop further processing if it's a status message --}}
                        @endif

                        <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200">
                            Stream Track #{{ $index }} 
                            @if(isset($detail['codec_type'])) {{-- Changed: Access codec_type directly on $detail --}}
                                ({{ ucfirst($detail['codec_type']) }})
                            @elseif(isset($detail['stream']['codec_type'])) {{-- Fallback for nested 'stream' structure --}}
                                ({{ ucfirst($detail['stream']['codec_type']) }})
                            @endif
                        </h4>
                        <div class="overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-gray-700">
                            <table class="min-w-full text-sm">
                                @php 
                                    // Determine the actual array of properties to iterate
                                    $propertiesToIterate = $detail['stream'] ?? $detail;
                                @endphp
                                @foreach ($propertiesToIterate as $key => $value)
                                    @if (is_scalar($value) || is_null($value))
                                        <tr>
                                            <td class="w-1/3 py-1 pr-2 font-medium text-gray-600 dark:text-gray-400">{{ str_replace('_', ' ', Illuminate\Support\Str::title($key)) }}</td>
                                            <td class="py-1 text-gray-800 dark:text-gray-200">{{ $value ?? 'N/A' }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </table>
                        </div>
                    @empty
                        <p>No specific stream track details available.</p>
                    @endforelse
                @else
                    <p>Loading stream details or no details to display...</p>
                @endif
            </div>

            <x-slot name="footer">
                <x-filament::button color="gray" wire:click="closeModal()" class="mr-auto">
                    Close
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    @endif
</x-filament-panels::page>
