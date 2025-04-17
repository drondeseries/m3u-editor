<?php

namespace App\Filament\Resources\CustomPlaylistResource\Pages;

use App\Filament\Resources\CustomPlaylistResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomPlaylists extends ListRecords
{
    protected static string $resource = CustomPlaylistResource::class;

    protected ?string $subheading = 'Create playlists composed of channels from your other playlists. Head to channels to bulk add channels to your custom playlist.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->successRedirectUrl(fn($record): string => EditCustomPlaylist::getUrl(['record' => $record])),
        ];
    }
}
