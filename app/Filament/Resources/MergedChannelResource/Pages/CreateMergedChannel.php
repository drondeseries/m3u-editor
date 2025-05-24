<?php

namespace App\Filament\Resources\MergedChannelResource\Pages;

use App\Filament\Resources\MergedChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model; // Added

class CreateMergedChannel extends CreateRecord
{
    protected static string $resource = MergedChannelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $sourceChannelsData = $data['sourceChannels'] ?? [];
        unset($data['sourceChannels']); // Remove repeater data from main data array

        // user_id should be handled by MutateFormDataBeforeCreate in the resource
        $record = static::getModel()::create($data);

        $syncData = [];
        foreach ($sourceChannelsData as $source) {
            if (!empty($source['source_channel_id'])) {
                $syncData[$source['source_channel_id']] = ['priority' => $source['priority'] ?? 0];
            }
        }
        $record->sourceChannels()->sync($syncData);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
