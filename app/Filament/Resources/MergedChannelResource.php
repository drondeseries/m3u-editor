<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedChannelResource\Pages;
use App\Models\Channel;
use App\Models\EpgChannel; // Added EpgChannel
use App\Models\MergedChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class MergedChannelResource extends Resource
{
    protected static ?string $model = MergedChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Channels';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Select::make('epg_channel_id')
                    ->relationship('epgChannel', 'name') // Assuming EpgChannel has a 'name' attribute
                    ->label('EPG Source Channel')
                    ->options(EpgChannel::query()->pluck('name', 'id')) // Adjust if EpgChannel uses a different display attribute
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Select an EPG channel to source program data for this merged channel.')
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('sourceChannels')
                    ->relationship('sourceChannels')
                    ->schema([
                        Forms\Components\Select::make('source_channel_id')
                            ->label('Channel')
                            ->options(Channel::query()->pluck('name', 'id'))
                            ->searchable(['name', 'id'])
                            ->required(),
                        Forms\Components\NumberInput::make('priority')
                            ->required()
                            ->default(0),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->addActionLabel('Add Source Channel')
                    ->reorderableWithButtons()
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('source_channels_count')
                    ->counts('sourceChannels')
                    ->label('Source Channels')
                    ->sortable(),
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
            'index' => Pages\ListMergedChannels::route('/'),
            'create' => Pages\CreateMergedChannel::route('/create'),
            'edit' => Pages\EditMergedChannel::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id());
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();
        return $data;
    }
}
