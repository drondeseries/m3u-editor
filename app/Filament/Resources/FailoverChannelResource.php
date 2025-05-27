<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailoverChannelResource\Pages;
use App\Models\FailoverChannel;
use App\Models\Channel; // Ensure Channel model is imported
use App\Models\CustomPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log; // Kept for now, can be removed in future if stable

class FailoverChannelResource extends Resource
{
    protected static ?string $model = FailoverChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Playlist';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->columnSpan('full'),
                Forms\Components\TextInput::make('speed_threshold')
                    ->label('Speed Threshold')
                    ->required()
                    ->numeric()
                    ->minValue(0.1)
                    ->maxValue(10.0)
                    ->step(0.1)
                    ->default(0.9)
                    ->helperText('If stream speed (e.g., 0.8x) falls below this, try next source.')
                    ->columnSpan('full'),
                Forms\Components\Section::make('TVG Overrides (Optional)')
                    ->description('Define specific TVG attributes for this Failover Channel entry in M3U/EPG outputs. If blank, attributes from the primary source channel may be used.')
                    ->collapsible()
                    ->collapsed() // Or false, as preferred
                    ->schema([
                        Forms\Components\TextInput::make('tvg_name_override')
                            ->label('TVG Name Override')
                            ->placeholder('e.g., My Custom Failover Name')
                            ->columnSpan('full'),
                        Forms\Components\TextInput::make('tvg_logo_override')
                            ->label('TVG Logo URL Override')
                            ->type('url')
                            ->placeholder('e.g., http://example.com/logo.png')
                            ->columnSpan('full'),
                        Forms\Components\TextInput::make('tvg_id_override')
                            ->label('TVG ID Override (XMLTV ID)')
                            ->placeholder('e.g., myfailover.tvg.id')
                            ->columnSpan('full'),
                        Forms\Components\TextInput::make('tvg_chno_override')
                            ->label('TVG Channel Number Override (tvg-chno)')
                            ->placeholder('e.g., 1001')
                            ->columnSpan('full'),
                        Forms\Components\TextInput::make('tvg_guide_stationid_override')
                            ->label('TVG Guide Station ID Override')
                            ->placeholder('e.g., 12345')
                            ->columnSpan('full'),
                    ])->columnSpan('full'),
                Forms\Components\Repeater::make('sources')
                    ->label('Source Channels (in order of failover)')
                    ->relationship()
                    ->schema([
                        Forms\Components\Select::make('channel_id')
                            ->label('Channel')
                            ->options(function () {
                                $customTitles = \App\Models\Channel::whereNotNull('title_custom')->pluck('title_custom', 'id');
                                $defaultTitles = \App\Models\Channel::whereNull('title_custom')->pluck('title', 'id');
                                return $customTitles->union($defaultTitles)->toArray();
                            })
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->columnSpan('full'),
                    ])
                    ->orderColumn('order')
                    ->columnSpan('full')
                    ->addActionLabel('Add Source Channel')
                    ->defaultItems(1)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed(false)
                    ->saveRelationshipsUsing(function (FailoverChannel $record, array $state): void {
                        $syncData = [];
                        $currentOrder = 1; // Initialize order counter (1-indexed)
                        
                        // $state array keys might be UUIDs, but iteration order is preserved.
                        foreach ($state as $itemKey => $itemData) { 
                            // Log::info('FailoverChannel Repeater Item (key ' . $itemKey . '): ' . json_encode($itemData)); // Still useful for one more test
                            if (!empty($itemData['channel_id'])) {
                                $syncData[$itemData['channel_id']] = ['order' => $currentOrder++];
                            }
                        }
                        $record->sources()->sync($syncData);
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_display')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(40)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tvg_channel_number_display')
                    ->label('TVG ChNo')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('sources_count')
                    ->label('Sources')
                    ->counts('sources')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('speed_threshold')
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('add_to_custom_playlist')
                        ->label('Add to custom playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected failover channel(s) to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->failoverChannels()->syncWithoutDetaching($records->pluck('id'));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Failover channels added to custom playlist')
                                ->body('The selected failover channels have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-plus-circle') // Changed icon to avoid conflict
                        ->modalIcon('heroicon-o-plus-circle') // Changed icon to avoid conflict
                        ->modalDescription('Add the selected failover channel(s) to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailoverChannels::route('/'),
            'create' => Pages\CreateFailoverChannel::route('/create'),
            'edit' => Pages\EditFailoverChannel::route('/{record}/edit'),
        ];
    }
}
