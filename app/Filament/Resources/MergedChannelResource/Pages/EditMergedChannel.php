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

    protected function fillForm(): void
    {
        parent::fillForm(); // Call parent to populate the main form fields

        Log::info('EditMergedChannel: fillForm called. Populating sourceChannels repeater manually.');

        $sourceChannelsData = [];
        // Access the relationship directly. If it's not loaded, Laravel will lazy-load it.
        if ($this->record && $this->record->sourceChannels) { 
            $sourceChannelsData = $this->record->sourceChannels->map(function ($relatedChannel) {
                // $relatedChannel is an instance of App\Models\Channel
                // 'source_channel_id' is the name of the Select field in the Repeater
                // 'priority' is the name of the TextInput field for priority in the Repeater
                return [
                    'source_channel_id' => $relatedChannel->id,       // The ID of the related Channel model
                    'priority'          => $relatedChannel->pivot->priority, // The priority from the pivot table
                    // 'selected_channel_url' will be populated by the reactive select's afterStateUpdated
                ];
            })->toArray();
            
            Log::info('EditMergedChannel: Prepared sourceChannelsData for repeater.', ['data_count' => count($sourceChannelsData), 'data' => $sourceChannelsData]);

        } else {
            Log::info('EditMergedChannel: No record or sourceChannels relationship found/loaded for populating repeater.');
        }

        // Prepare data for form fill, including the main 'name' field
        $formData = ['sourceChannels' => $sourceChannelsData];
        if ($this->record && isset($this->record->name)) {
            $formData['name'] = $this->record->name;
        } else {
            // Log if record or name is unexpectedly missing, though parent::fillForm should handle it
            Log::warning('EditMergedChannel: Record or record name is missing when trying to explicitly fill name.', ['record_exists' => !!$this->record]);
        }
        
        $this->form->fill($formData);
        Log::info('EditMergedChannel: Form fill attempted for sourceChannels and name data.');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $sourceChannelsData = $data['sourceChannels'] ?? [];
        unset($data['sourceChannels']); // Remove repeater data

        // Explicitly set main attributes, including TVG fields
        $record->name = $data['name'] ?? $record->name; // Keep existing name if not provided
        $record->epg_channel_id = $data['epg_channel_id'] ?? null; // Set to null if not provided

        // Explicitly set TVG fields from $data if they exist, otherwise retain original or set to null/empty
        // For TVG fields, if they are not in $data, it means they were not part of the form submission
        // (e.g., hidden by conditional logic or not included in $fillable if that was an issue, though less likely for fillable).
        // The behavior here is to update them if present in $data, otherwise they remain as they are on $record.
        // If the intention is to NULL them out if not present, then a different approach would be needed
        // (e.g. $record->tvg_id = $data['tvg_id'] ?? null; for all TVG fields)
        // Given the problem description, we only update if key_exists.

        if (array_key_exists('tvg_id', $data)) {
            $record->tvg_id = $data['tvg_id'];
        }
        if (array_key_exists('tvg_name', $data)) {
            $record->tvg_name = $data['tvg_name'];
        }
        if (array_key_exists('tvg_logo', $data)) {
            $record->tvg_logo = $data['tvg_logo'];
        }
        if (array_key_exists('tvg_chno', $data)) {
            $record->tvg_chno = $data['tvg_chno'];
        }
        if (array_key_exists('tvc_guide_stationid', $data)) {
            $record->tvc_guide_stationid = $data['tvc_guide_stationid'];
        }
        
        // Log what is about to be saved from the main $data array, excluding sourceChannels
        // Log::info('EditMergedChannel: Data being saved to record (excluding sourceChannels)', ['data_to_save' => $data, 'record_id' => $record->id]);

        $record->save();

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
