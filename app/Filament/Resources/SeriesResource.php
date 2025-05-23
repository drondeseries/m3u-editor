<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Resources\SeriesResource\RelationManagers;
use App\Models\Series;
use App\Rules\CheckIfUrlOrLocalPath;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'plot', 'genre', 'release_date', 'director'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->width(80)
                    ->height(120)
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->description((fn($record) => Str::limit($record->plot, 200)))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 400px;'])
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle series status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('seasons_count')
                    ->label('Seasons')
                    ->counts('seasons')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('episodes_count')
                    ->label('Episodes')
                    ->counts('episodes')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('genre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('youtube_trailer')
                    ->label('YouTube Trailer')
                    ->placeholder('No trailer ID set.')
                    ->url(fn($record): string => 'https://www.youtube.com/watch?v=' . $record->youtube_trailer)
                    ->openUrlInNewTab()
                    ->icon('heroicon-s-play'),
                Tables\Columns\TextColumn::make('release_date')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating_5based')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('process')
                        ->label('Process Series')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $record,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series is being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                    series: $record,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series .strm files are being synced')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.')
                        ->modalSubmitActionLabel('Yes, sync now')
                        ->disabled(fn($record): bool => ! $record->sync_location),
                    Tables\Actions\DeleteAction::make()
                        ->modalIcon('heroicon-o-trash')
                        ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete series'),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\Action::make('process')
                        ->label('Process Selected Series')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                        playlistSeries: $record,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series are being processed')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                        series: $record,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('.strm files are being synced for selected series')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync selected series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                        ->modalSubmitActionLabel('Yes, sync now'),
                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected series enabled')
                                ->body('The selected series have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected series disabled')
                                ->body('The selected series have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeries::route('/'),
            //'create' => Pages\CreateSeries::route('/create'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\Grid::make()
                ->columns(4)
                ->schema([
                    Forms\Components\Section::make('Series Details')
                        ->columnSpan(2)
                        ->icon('heroicon-o-pencil')
                        ->description('Edit or add the series details')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->disabled()
                                        ->maxLength(255),
                                    Forms\Components\Toggle::make('enabled')
                                        ->inline(false)
                                        ->required(),
                                    Forms\Components\Select::make('category_id')
                                        ->relationship('category', 'name'),
                                    Forms\Components\TextInput::make('cover')
                                        ->maxLength(255),
                                    Forms\Components\Textarea::make('plot')
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('genre')
                                        ->maxLength(255),
                                    Forms\Components\DatePicker::make('release_date')
                                        ->label('Release Date'),
                                    Forms\Components\TextInput::make('rating')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('rating_5based')
                                        ->label('Rating (5 based)')
                                        ->numeric(),
                                    Forms\Components\Textarea::make('cast')
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('director')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('backdrop_path'),
                                    Forms\Components\TextInput::make('youtube_trailer')
                                        ->label('YouTube Trailer ID')
                                        ->maxLength(255),
                                ]),
                        ]),
                    Forms\Components\Section::make('Stream location file settings')
                        ->columnSpan(2)
                        ->icon('heroicon-o-cog')
                        ->description('Generate .strm files and sync them to a local file path')
                        ->collapsible()
                        ->schema([
                            Forms\Components\Grid::make(1)
                                ->schema([
                                    Forms\Components\Toggle::make('sync_settings.enabled')
                                        ->live()
                                        ->label('Enable .strm file generation'),
                                    Forms\Components\Toggle::make('sync_settings.include_series')
                                        ->label('Create series folder')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    Forms\Components\Toggle::make('sync_settings.include_season')
                                        ->label('Create season folders')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    Forms\Components\TextInput::make('sync_location')
                                        ->label('Series Sync Location')
                                        ->live()
                                        ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                        ->helperText(
                                            fn($get) => !$get('sync_settings.include_series')
                                                ? 'File location: ' . $get('sync_location') . ($get('sync_settings.include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                                : 'File location: ' . $get('sync_location') . '/Series Name' . ($get('sync_settings.include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                        )
                                        ->maxLength(255)
                                        ->required()
                                        ->hidden(fn($get) => !$get('sync_settings.enabled'))
                                        ->placeholder('/usr/local/bin/streamlink'),
                                ]),
                        ]),
                ]),
        ];
    }
}
