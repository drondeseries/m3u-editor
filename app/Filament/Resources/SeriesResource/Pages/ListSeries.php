<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Resources\SeriesResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSeries extends ListRecords
{
    protected static string $resource = SeriesResource::class;

    protected ?string $subheading = 'NOTE: Only enabled series will be autmatically synced and updated on Playlist sync.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create')
                ->slideOver()
                ->label('Add Series')
                ->steps(SeriesResource::getFormSteps())
                ->color('primary')
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\SyncXtreamSeries(
                            playlist: $data['playlist'],
                            catId: $data['category'],
                            catName: $data['category_name'],
                            series: $data['series'],
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Series have been added and are being processed.')
                        ->body('You will be notified when the process is complete.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('2xl')
                ->modalIcon(null)
                ->modalDescription('Select the playlist Series you would like to add.')
                ->modalSubmitActionLabel('Create'),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
