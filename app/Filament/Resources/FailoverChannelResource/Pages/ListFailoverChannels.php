<?php

namespace App\Filament\Resources\FailoverChannelResource\Pages;

use App\Filament\Resources\FailoverChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFailoverChannels extends ListRecords
{
    protected static string $resource = FailoverChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
