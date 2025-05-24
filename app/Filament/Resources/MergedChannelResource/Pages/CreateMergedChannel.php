<?php

namespace App\Filament\Resources\MergedChannelResource\Pages;

use App\Filament\Resources\MergedChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model; // Added
use Illuminate\Support\Facades\Log; // Added
use Illuminate\Support\Facades\Auth; // Added

class CreateMergedChannel extends CreateRecord
{
    protected static string $resource = MergedChannelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        Log::info('CreateMergedChannel: handleRecordCreation started.', ['data' => $data]);

        $sourceChannelsData = $data['sourceChannels'] ?? [];
        unset($data['sourceChannels']); // Remove repeater data from main data array

        // user_id should be handled by MutateFormDataBeforeCreate in the resource
        // but let's log it if it's in $data to be sure.
        Log::info('CreateMergedChannel: Data before creating MergedChannel model.', ['data_for_model' => $data]);

        // Ensure 'user_id' is present before creating the model.
        // This is a defensive measure. Ideally, it should always be set by MutateFormDataBeforeCreate.
        // Let's use $data as it's named in the existing method after unsetting sourceChannels.
        if (empty($data['user_id'])) {
            $currentAuthId = Auth::id();
            Log::warning('CreateMergedChannel: user_id was missing or empty in $data just before model creation. Defensively setting it.', [
                'data_received' => $data, // Log the data that was missing user_id
                'auth_id_being_set' => $currentAuthId
            ]);
            if (is_null($currentAuthId)) {
                Log::error('CreateMergedChannel: Auth::id() is NULL even for defensive assignment! Record will likely still have null user_id or fail if user_id is non-nullable without default.');
                // Not throwing exception here to see if it saves with NULL or db default.
            }
            $data['user_id'] = $currentAuthId;
        } else {
            Log::info('CreateMergedChannel: user_id is present in $data before model creation.', ['user_id' => $data['user_id']]);
        }

        $record = null;
        try {
            $record = static::getModel()::create($data);
            Log::info('CreateMergedChannel: MergedChannel model created.', ['record_id' => $record->id, 'record_exists' => $record->exists]);
        } catch (\Exception $e) {
            Log::error('CreateMergedChannel: Exception during MergedChannel model creation.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(), // Be cautious with trace in production logs due to verbosity
                'data' => $data
            ]);
            // Re-throw the exception to let Filament handle the error notification,
            // or handle it by returning null/redirecting with error if that's preferred
            // For now, re-throwing is fine to see if Filament catches it.
            throw $e; 
        }

        // Ensure record was created before proceeding
        if (!$record || !$record->exists) {
            Log::error('CreateMergedChannel: Record was not created or does not exist after create call. Aborting sync.');
            // This situation should ideally be caught by the exception block above if create() fails.
            // If create() returns null without an exception (highly unlikely for Eloquent create),
            // this would be a fallback. Filament might show a generic error.
            // Consider throwing a new specific exception here.
            throw new \RuntimeException('Failed to create MergedChannel record, cannot proceed with syncing source channels.');
        }

        // Manual sync logic for sourceChannels removed.
        // Filament's Repeater with ->relationship('sourceChannels') will handle this.
        // The $sourceChannelsData variable is still populated from $data['sourceChannels']
        // before it's unset, but it's no longer used in this method.

        if (!empty($sourceChannelsData)) {
            $syncData = [];
            foreach ($sourceChannelsData as $source) {
                if (!empty($source['source_channel_id'])) {
                    // 'selected_channel_url' is a UI-only field, do not include it in syncData
                    $syncData[$source['source_channel_id']] = ['priority' => $source['priority'] ?? 0];
                }
            }
            Log::info('CreateMergedChannel: Data for manual syncing of sourceChannels.', ['sync_data' => $syncData, 'merged_channel_id' => $record->id]);
            try {
                $record->sourceChannels()->sync($syncData);
                Log::info('CreateMergedChannel: sourceChannels manually synced successfully.');
            } catch (\Exception $e) {
                Log::error('CreateMergedChannel: Exception during manual sourceChannels sync.', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(), // Consider verbosity
                    'merged_channel_id' => $record->id,
                    'sync_data' => $syncData
                ]);
                // Depending on desired behavior, one might want to delete the $record here or handle it.
                throw $e; // Re-throw to notify user of failure
            }
        } else {
            Log::info('CreateMergedChannel: No sourceChannelsData provided for manual sync.');
            // If an empty array should clear existing relations (it should for sync), ensure sync is called.
            // $record->sourceChannels()->sync([]); // Uncomment if empty repeater should clear all associations.
                                                // Usually, if $sourceChannelsData is empty, $syncData will be empty,
                                                // and sync([]) will detach all. This is often desired.
                                                // Let's ensure sync is called even with empty $syncData if $sourceChannelsData was present.
            // The prompt uses $data['sourceChannels'] here, but $data['sourceChannels'] was already unset.
            // The intention is to check if the repeater was part of the form submission, even if empty.
            // $sourceChannelsData captures this initial state. If it's an empty array (but not null), it means the repeater was submitted empty.
            // $this->data holds the original form data before this method is called.
            if (is_array($sourceChannelsData) && empty($sourceChannelsData) && array_key_exists('sourceChannels', $this->data)) {
                Log::info('CreateMergedChannel: Empty sourceChannels in form, syncing empty array to detach all.');
                $record->sourceChannels()->sync([]);
            }
        }

        Log::info('CreateMergedChannel: handleRecordCreation completed.', ['final_record_id' => $record->id]);
        return $record;
    }

    protected function getRedirectUrl(): string
    {
        Log::info('CreateMergedChannel: getRedirectUrl called.');
        if ($this->record) {
            Log::info('CreateMergedChannel: Record details for redirect.', [
                'record_id' => $this->record->id ?? 'ID not set',
                'record_exists_in_db' => $this->record->exists ?? 'N/A (exists property not available or record is null)',
                'record_class' => get_class($this->record)
            ]);
            // Check if the record has an ID, which is crucial for route generation.
            if (empty($this->record->id)) {
                Log::error('CreateMergedChannel: Record ID is missing in getRedirectUrl. Redirect might fail.');
                // Potentially handle this error, e.g., redirect to index with an error message.
                // For now, logging is the primary goal.
            }
        } else {
            Log::warning('CreateMergedChannel: $this->record is null in getRedirectUrl. Cannot generate edit URL.');
            // This indicates a problem, as the record should have been set by Filament's CreateRecord page
            // after successful handleRecordCreation.
            // Redirect to index as a fallback.
            return $this->getResource()::getUrl('index'); 
        }

        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
