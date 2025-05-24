<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedChannelResource\Pages;
use App\Models\Channel;
use App\Models\EpgChannel; // Added EpgChannel
use App\Models\MergedChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get; // Added
use Filament\Forms\Set; // Added
// use Filament\Forms\Components\Fieldset; // Removed
use Filament\Forms\Components\Placeholder; // Added
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Added

class MergedChannelResource extends Resource
{
    protected static ?string $model = MergedChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Channels';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Channel Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        // ->columnSpanFull(), // Removed to allow section to control span
                        Forms\Components\Select::make('epg_channel_id')
                            ->relationship('epgChannel', 'name') // Assuming EpgChannel has a 'name' attribute
                            ->label('EPG Source Channel')
                            ->options(EpgChannel::query()->pluck('name', 'id')) // Adjust if EpgChannel uses a different display attribute
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Select an EPG channel to source program data for this merged channel.'),
                        // ->columnSpanFull(), // Removed to allow section to control span
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Forms\Components\Section::make('Source Channels Configuration')
                    ->schema([
                        Forms\Components\Repeater::make('sourceChannels')
                            // ->relationship('sourceChannels') // Removed as per instruction
                            ->schema([
                                Forms\Components\Select::make('source_channel_id')
                                    ->label('Channel')
                                    ->options(Channel::query()->pluck('name', 'id'))
                                    ->searchable(['name', 'id'])
                                    ->required()
                                    ->reactive() // Make it reactive
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        if ($state) {
                                            $channel = Channel::find($state);
                                            if ($channel) {
                                                $set('selected_channel_url', $channel->url_custom ?? $channel->url);
                                            } else {
                                                $set('selected_channel_url', null); // Channel not found
                                            }
                                        } else {
                                            $set('selected_channel_url', null); // State cleared
                                        }
                                    }),
                                Forms\Components\TextInput::make('selected_channel_url')
                                    ->label('Selected Channel URL')
                                    ->disabled()
                                    ->placeholder('Select a channel to see its URL'),
                                Forms\Components\TextInput::make('priority')
                                    ->numeric() // Use TextInput with numeric validation
                                    ->required()
                                    ->default(0)
                                    ->helperText("Lower numbers indicate higher priority (e.g., 0 is highest). Channels will be tried in order of priority."),
                            ])
                            ->columns(3) // Changed to 3 columns
                            ->defaultItems(1)
                            ->addActionLabel('Add Source Channel')
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->columnSpanFull(), // Repeater itself can span full within its section
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                
                Forms\Components\Section::make('Stream URLs') // Changed from Fieldset
                    ->label('Generated Stream URLs')
                    ->collapsible()
                    ->visibleOn('edit') // Only visible on the edit page
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('placeholder_stream_url_ts')
                            ->label('MPEG-TS Stream URL')
                            ->content(function (?MergedChannel $record): string {
                                if ($record && $record->id) {
                                    return route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'ts']); // Corrected route name
                                }
                                return 'URL will be available after saving.';
                            }),
                        Placeholder::make('placeholder_stream_url_mp4')
                            ->label('MP4 Stream URL')
                            ->content(function (?MergedChannel $record): string {
                                if ($record && $record->id) {
                                    return route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'mp4']); // Corrected route name
                                }
                                return 'URL will be available after saving.';
                            }),
                        Placeholder::make('placeholder_stream_url_flv')
                            ->label('FLV Stream URL')
                            ->content(function (?MergedChannel $record): string {
                                if ($record && $record->id) {
                                    return route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'flv']); // Corrected route name
                                }
                                return 'URL will be available after saving.';
                            }),
                    ]),
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
        Log::info('MergedChannelResource: mutateFormDataBeforeCreate started.', ['incoming_data' => $data]);
        
        $authId = Auth::id();
        Log::info('MergedChannelResource: Auth::id() value.', ['auth_id' => $authId]);

        if (is_null($authId)) {
            Log::error('MergedChannelResource: Auth::id() is NULL in mutateFormDataBeforeCreate! This is unexpected.');
            // Potentially throw an exception or handle error, as user_id will be null.
            // For now, logging is key. The defensive step in handleRecordCreation will catch it.
        }
        
        $data['user_id'] = $authId;
        Log::info('MergedChannelResource: Data after adding user_id.', ['data_with_user_id' => $data]);
        
        return $data;
    }
}
