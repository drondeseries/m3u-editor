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

        $syncData = [];
        foreach ($sourceChannelsData as $source) {
            if (!empty($source['source_channel_id'])) {
                $syncData[$source['source_channel_id']] = ['priority' => $source['priority'] ?? 0];
            }
        }
        $record->sourceChannels()->sync($syncData);

        return $record;
    }
}
