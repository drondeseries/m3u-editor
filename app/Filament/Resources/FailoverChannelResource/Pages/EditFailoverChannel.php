<?php

namespace App\Filament\Resources\FailoverChannelResource\Pages;

use App\Filament\Resources\FailoverChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFailoverChannel extends EditRecord
{
    protected static string $resource = FailoverChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
