<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Enums\PlaylistStatus;
use App\Filament\Resources\PlaylistResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPlaylist extends EditRecord
{
    use EditRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('process')
                    ->label('Process')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($record) {
                        $record->update([
                            'status' => PlaylistStatus::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is processing')
                            ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn($record): bool => $record->status === PlaylistStatus::Processing)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('Process playlist now?')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('Download M3U')
                    ->label('Download M3U')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn($record) => \App\Facades\PlaylistUrlFacade::getUrls($record)['m3u'])
                    ->openUrlInNewTab(),
                Actions\Action::make('Download M3U')
                    ->label('Download EPG')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn($record) => \App\Facades\PlaylistUrlFacade::getUrls($record)['epg'])
                    ->openUrlInNewTab(),
                Actions\Action::make('HDHomeRun URL')
                    ->label('HDHomeRun URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record) => \App\Facades\PlaylistUrlFacade::getUrls($record)['hdhr'])
                    ->openUrlInNewTab(),
                Actions\Action::make('Duplicate')
                    ->label('Duplicate')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Playlist name')
                            ->required()
                            ->helperText('This will be the name of the duplicated playlist.'),
                    ])
                    ->action(function ($record, $data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\DuplicatePlaylist($record, $data['name']));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is being duplicated')
                            ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-duplicate')
                    ->modalIcon('heroicon-o-document-duplicate')
                    ->modalDescription('Duplicate playlist now?')
                    ->modalSubmitActionLabel('Yes, duplicate now'),
                Actions\Action::make('reset')
                    ->label('Reset status')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->action(function ($record) {
                        $record->update([
                            'status' => PlaylistStatus::Pending,
                            'processing' => false,
                            'progress' => 0,
                            'channels' => 0,
                            'synced' => null,
                            'errors' => null,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist status reset')
                            ->body('Playlist status has been reset.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                    ->modalSubmitActionLabel('Yes, reset now'),
                Actions\DeleteAction::make(),
            ])->button(),
        ];
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }
}
