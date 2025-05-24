<?php

namespace App\Filament\Resources\MergedChannelResource\Pages;

use App\Filament\Resources\MergedChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model; // Added

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

        return $record;
    }
}
