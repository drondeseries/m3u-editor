<?php

namespace App\Filament\Resources\MergedChannelResource\Pages;

use App\Filament\Resources\MergedChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model; // Added
use Illuminate\Support\Facades\Log; // Added

class EditMergedChannel extends EditRecord
{
    protected static string $resource = MergedChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $sourceChannelsData = $data['sourceChannels'] ?? [];
        unset($data['sourceChannels']); // Remove repeater data from main data array

        $record->update($data);

        // Manual sync logic for sourceChannels removed.
        // Filament's Repeater with ->relationship('sourceChannels') will handle this.
        // The $sourceChannelsData variable is still populated from $data['sourceChannels']
        // before it's unset, but it's no longer used in this method.
        // Log this change for clarity during debugging, if necessary.
        // Log::info('EditMergedChannel: handleRecordUpdate completed, relying on Filament for relationship sync.', ['record_id' => $record->id]);

        if (!empty($sourceChannelsData)) {
            $syncData = [];
            foreach ($sourceChannelsData as $source) {
                if (!empty($source['source_channel_id'])) {
                    // 'selected_channel_url' is a UI-only field, do not include it in syncData
                    $syncData[$source['source_channel_id']] = ['priority' => $source['priority'] ?? 0];
                }
            }
            Log::info('EditMergedChannel: Data for manual syncing of sourceChannels.', ['sync_data' => $syncData, 'merged_channel_id' => $record->id]);
            try {
                $record->sourceChannels()->sync($syncData);
                Log::info('EditMergedChannel: sourceChannels manually synced successfully.');
            } catch (\Exception $e) {
                Log::error('EditMergedChannel: Exception during manual sourceChannels sync.', [
                    'error' => $e->getMessage(),
                    'merged_channel_id' => $record->id,
                    'sync_data' => $syncData
                ]);
                throw $e; // Re-throw
            }
        } else {
            Log::info('EditMergedChannel: No sourceChannelsData provided for manual sync.');
            // If an empty repeater should clear all relations:
            // Check if 'sourceChannels' was part of the form submission (even if empty)
            // $this->data should hold the original form data.
            if (is_array($sourceChannelsData) && array_key_exists('sourceChannels', $this->data)) {
                 Log::info('EditMergedChannel: Empty sourceChannels in form, syncing empty array to detach all.');
                $record->sourceChannels()->sync([]);
            }
        }
        return $record;
    }
}
