<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailoverChannelResource\Pages;
use App\Models\FailoverChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                Forms\Components\Repeater::make('sources')
                    ->label('Source Channels (in order of failover)')
                    ->relationship()
                    ->schema([
                        Forms\Components\Select::make('channel_id')
                            ->label('Channel')
                            ->options(function () {
                                $customTitles = \App\Models\Channel::whereNotNull('title_custom')->pluck('title_custom', 'id');
                                $defaultTitles = \App\Models\Channel::whereNull('title_custom')->pluck('title', 'id');
                                // Merge, ensuring custom titles take precedence if IDs overlap (though IDs should be unique)
                                return $customTitles->union($defaultTitles)->toArray();
                            })
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->columnSpan('full'),
                        // The 'order' field is handled by orderColumn on the Repeater itself.
                    ])
                    ->orderColumn('order') // Enables drag-and-drop and handles 'order' pivot attribute.
                    ->columnSpan('full')
                    ->addActionLabel('Add Source Channel')
                    ->defaultItems(1)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
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
