<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomPlaylistResource\Pages;
use App\Filament\Resources\CustomPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Forms\Components\MediaFlowProxyUrl;
use App\Models\CustomPlaylist;
use App\Models\MergedChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Get;
use Filament\Forms\Components\Placeholder; // Added for new display fields
use Filament\Forms\Components\TextInput; // Added for TextInput
use Filament\Forms\Components\Actions\Action; // Added for suffixAction
use Illuminate\Support\HtmlString; // Added for suffixAction
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Facades\PlaylistUrlFacade;
use Filament\Forms\FormsComponent;

class CustomPlaylistResource extends Resource
{
    protected static ?string $model = CustomPlaylist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Custom';

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    private static function getMergedChannelsFormField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('mergedChannels')
            ->relationship('mergedChannels', 'name') // Assumes 'name' is the title attribute on MergedChannel
            ->multiple()
            ->preload()
            ->searchable()
            ->helperText('Select merged channels to include in this playlist.')
            ->columnSpanFull();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('enabled_channels');
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->description(fn(CustomPlaylist $record): string => "Enabled: {$record->enabled_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
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
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download EPG')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalHeading('Download EPG')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Select the EPG format to download and your download will begin immediately.')
                        ->modalWidth('md')
                        ->modalFooterActions([
                            Tables\Actions\Action::make('uncompressed')
                                ->requiresConfirmation()
                                ->label('Download uncompressed EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg'])),
                            Tables\Actions\Action::make('compressed')
                                ->requiresConfirmation()
                                ->label('Download gzip EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg_zip']))
                        ])
                        ->modalSubmitActionLabel('Download EPG'),
                    Tables\Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun Url')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm')
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
            RelationManagers\TagsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomPlaylists::route('/'),
            // 'create' => Pages\CreateCustomPlaylist::route('/create'),
            'edit' => Pages\EditCustomPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->helperText('Enter the name of the playlist. Internal use only.')
                ->unique(CustomPlaylist::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule, Get $get) {
                    // Scope the uniqueness check to the current user.
                    // And ignore the current record if we are in an edit context.
                    $userId = Auth::id();
                    $query = CustomPlaylist::where('user_id', $userId);

                    // If we are in an edit form, the record ID will be available.
                    // $recordId = $get('id'); // This might not be reliable or always present for uniqueness checks on 'name'
                                          // It's better to rely on ignoreRecord: true for the current record.
                                          // However, Filament's built-in unique rule with ignoreRecord: true handles this.
                                          // We just need to ensure it's scoped to the user.

                    return $rule->where(function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    });
                })
                ->validationMessages([
                    'unique' => 'A playlist with this name already exists for your account.',
                ]),
            Forms\Components\TextInput::make('user_agent')
                ->helperText('User agent string to use for making requests.')
                ->default('Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13')
                ->required(),
            // self::getMergedChannelsFormField(), // Removed as per new requirement for Repeater display
        ];
        if (PlaylistUrlFacade::mediaFlowProxyEnabled()) {
            $schema[] = Forms\Components\Section::make('MediaFlow Proxy')
                ->description('Your MediaFlow Proxy generated links – to disable clear the MediaFlow Proxy values from the app Settings page.')
                ->collapsible()
                ->collapsed(true)
                ->headerActions([
                    Forms\Components\Actions\Action::make('mfproxy_git')
                        ->label('GitHub')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->color('gray')
                        ->size('sm')
                        ->url('https://github.com/mhdzumair/mediaflow-proxy')
                        ->openUrlInNewTab(true),
                    Forms\Components\Actions\Action::make('mfproxy_docs')
                        ->label('Docs')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        ->url(fn($record) => PlaylistUrlFacade::getMediaFlowProxyServerUrl($record) . '/docs')
                        ->openUrlInNewTab(true),
                ])
                ->schema([
                    MediaFlowProxyUrl::make('mediaflow_proxy_url')
                        ->label('Proxied M3U URL')
                        ->columnSpan(2)
                        ->dehydrated(false) // don't save the value in the database
                ])->hiddenOn(['create']);
        }
        $outputScheme = [
            Forms\Components\Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(true)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    Forms\Components\TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
            Forms\Components\Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(true)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('dummy_epg')
                        ->label('Enably dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel name and the set program length are used.'),
                    Forms\Components\Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel Number (recommended for HDHR)',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg'))
                        ->required(),
                ]),
            Forms\Components\Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(true)
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('streams')
                        ->helperText('Number of streams available (currently used for HDHR service).')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(1) // Default to 1 stream
                        ->required(),
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, playlists urls will be proxied through m3u editor and streamed via ffmpeg.'),
                ])
        ];
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema([
                    ...$schema,
                    ...$outputScheme
                ])
                ->columns(2),
            Forms\Components\Grid::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->columns(5)
                ->schema([
                    Forms\Components\Tabs::make('tabs')
                        ->columnSpan(3)
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('General')
                                ->columns(2)
                                ->schema([
                                    ...$schema, // Spread the existing general schema fields
                                    // New Merged Channels Display and Management Section
                                    Forms\Components\Section::make('Associated Merged Channels')
                                        ->description('Manage merged channels associated with this custom playlist.')
                                        ->collapsible()
                                        ->collapsed(false) // Default to open
                                        ->schema([
                                            Forms\Components\Repeater::make('mergedChannels') // Named after the relationship
                                                ->relationship() 
                                                ->schema([
                                                    Forms\Components\Grid::make(2) // Use a grid for better layout
                                                        ->schema([
                                                            Placeholder::make('name_display') // Changed from TextInput
                                                                ->label('Name')
                                                                ->content(fn (?MergedChannel $record): string => $record?->name ?? 'N/A'),
                                                            Forms\Components\TextInput::make('stream_url_display') // Changed name to avoid conflict if 'stream_url' is a real attribute
                                                                ->label('Stream URL')
                                                                ->disabled()
                                                                ->formatStateUsing(fn (?MergedChannel $record): string => $record ? route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'ts']) : 'N/A')
                                                                ->helperText('MPEG-TS Stream URL.')
                                                                ->suffixAction(
                                                                    \Filament\Forms\Components\Actions\Action::make('copyUrl')
                                                                        ->icon('heroicon-o-clipboard-document')
                                                                        ->label('') // Ensure no text label, just icon
                                                                        ->tooltip('Copy Stream URL')
                                                                        ->action(null) // No server-side action needed
                                                                        ->extraAttributes([
                                                                            'onclick' => new \Illuminate\Support\HtmlString(
                                                                                "navigator.clipboard.writeText(this.closest('.fi-input-wrp').querySelector('input').value)" .
                                                                                ".then(() => { Filament.notify('success', 'URL copied to clipboard'); })" .
                                                                                ".catch(err => { Filament.notify('danger', 'Failed to copy URL'); console.error('Failed to copy: ', err); });"
                                                                            ),
                                                                            'style' => 'margin-left: -0.5rem; padding: 0.25rem;'
                                                                        ])
                                                                ),
                                                            Placeholder::make('epg_source')
                                                                ->label('EPG Source')
                                                                ->content(fn (?MergedChannel $record): string => $record?->epgChannel?->name ?? 'N/A'),
                                                            Placeholder::make('source_count')
                                                                ->label('Source Channels')
                                                                ->content(fn (?MergedChannel $record): string => $record ? $record->sourceChannels()->count() . ' sources' : 'N/A'),
                                                        ])
                                                ])
                                                ->itemLabel(fn (array $state): ?string => 
                                                    // If Filament loads the related model's attributes into $state:
                                                    $state['name'] ?? 'Merged Channel Item' 
                                                )
                                                ->reorderable(false)
                                                ->addable(false) // Disable creating new MergedChannels from here
                                                ->deletable(true)  // Enables detach for BelongsToMany items
                                                ->columnSpanFull()
                                                ->itemActions([
                                                    Forms\Components\Actions\Action::make('view_merged_channel_item') // Renamed action for clarity
                                                        ->label('View')
                                                        ->icon('heroicon-m-eye')
                                                        ->url(function (CustomPlaylist $playlistRecord, Forms\Components\Repeater $component, string $item): ?string {
                                                            // $item is the key of the repeater item, which is the ID of the MergedChannel for BelongsToMany
                                                            $mergedChannel = MergedChannel::find($item); // Fetch the MergedChannel model directly
                                                            return $mergedChannel ? MergedChannelResource::getUrl('edit', ['record' => $mergedChannel]) : null;
                                                        })
                                                        ->openUrlInNewTab(),
                                                ])
                                                ->headerActions([
                                                    Forms\Components\Actions\Action::make('attach_merged_channels_action') // Renamed action for clarity
                                                        ->label('Attach Merged Channels')
                                                        ->form([
                                                            Forms\Components\Select::make('merged_channel_ids_to_attach')
                                                                ->label('Select Merged Channels')
                                                                ->multiple()
                                                                ->options(function (Get $get, CustomPlaylist $record) {
                                                                    // Get IDs of already attached merged channels for this custom playlist
                                                                    $attachedIds = $record->mergedChannels()->pluck('merged_channels.id')->toArray();
                                                                    // Offer options from MergedChannels belonging to the user, excluding already attached ones
                                                                    return MergedChannel::where('user_id', $record->user_id)
                                                                        ->whereNotIn('id', $attachedIds)
                                                                        ->pluck('name', 'id');
                                                                })
                                                                ->preload()
                                                                ->searchable()
                                                                ->required(),
                                                        ])
                                                        ->action(function (CustomPlaylist $record, array $data) {
                                                            $record->mergedChannels()->attach($data['merged_channel_ids_to_attach']);
                                                        }),
                                                ])
                                        ])->hiddenOn('create'), // Only show this section on edit
                                ]),
                            Forms\Components\Tabs\Tab::make('Output')
                                ->columns(2)
                                ->schema($outputScheme),
                        ]),
                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->columnSpan(2)
                        ->schema([
                            Forms\Components\Section::make('Auth')
                                ->description('Add authentication to your playlist.')
                                ->icon('heroicon-m-key')
                                ->collapsible()
                                ->collapsed(true)
                                ->schema([
                                    Forms\Components\Select::make('auth')
                                        ->relationship('playlistAuths', 'playlist_auths.name')
                                        ->label('Assigned Auth(s)')
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->helperText('NOTE: only the first enabled auth will be used if multiple assigned.'),
                                ]),
                            Forms\Components\Section::make('Links')
                                ->icon('heroicon-m-link')
                                ->collapsible()
                                ->collapsed(false)
                                ->schema([
                                    Forms\Components\Toggle::make('short_urls_enabled')
                                        ->label('Use Short URLs')
                                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                                        ->columnSpan(2)
                                        ->inline(false)
                                        ->default(false),
                                    PlaylistM3uUrl::make('m3u_url')
                                        ->label('M3U URL')
                                        ->columnSpan(2)
                                        ->dehydrated(false), // don't save the value in the database
                                    PlaylistEpgUrl::make('epg_url')
                                        ->label('EPG URL')
                                        ->columnSpan(2)
                                        ->dehydrated(false) // don't save the value in the database
                                ])
                        ]),
                ]),

        ];
    }
}
