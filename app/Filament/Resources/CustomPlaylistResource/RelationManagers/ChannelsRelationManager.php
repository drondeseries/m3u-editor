<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Enums\ChannelLogoType;
use App\Filament\Resources\ChannelResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\SpatieTagsColumn;
use Spatie\Tags\Tag;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return ChannelResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;
        return $table
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            // ->modifyQueryUsing(function (Builder $query) {
            //     $query->with('tags');
            // })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(40)
                    ->width('auto')
                    ->getStateUsing(function ($record) {
                        if ($record->logo_type === ChannelLogoType::Channel) {
                            return $record->logo;
                        }
                        return $record->epgChannel?->icon ?? $record->logo;
                    })
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('sort')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Channel sort order')
                    ->disabled(fn($record) => $record->playlist->auto_sort)
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('stream_id_custom')
                    ->label('ID')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->stream_id)
                    ->placeholder(fn($record) => $record->stream_id)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('title_custom')
                    ->label('Title')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->title)
                    ->placeholder(fn($record) => $record->title)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('name_custom')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->name)
                    ->placeholder(fn($record) => $record->name)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle channel status')
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('channel')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Channel No.')
                    ->tooltip('Channel number')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('url_custom')
                    ->label('URL')
                    ->rules(['url'])
                    ->type('url')
                    ->tooltip('Channel url')
                    ->placeholder(fn($record) => $record->url)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('shift')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Shift')
                    ->tooltip('Shift')
                    ->toggleable()
                    ->sortable(),
                SpatieTagsColumn::make('tags')
                    ->label('Playlist Group')
                    ->type($ownerRecord->uuid)
                    ->toggleable()
                    // ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->label('Default Group')
                    ->toggleable()
                    ->searchable(query: function ($query, string $search): Builder {
                        return $query->orWhereRaw('LOWER("group"::text) LIKE ?', ["%{$search}%"]);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Channel')
                    ->toggleable()
                    ->searchable()
                    ->limit(40)
                    ->sortable(),
                Tables\Columns\SelectColumn::make('logo_type')
                    ->label('Preferred Icon')
                    ->options([
                        'channel' => 'Channel',
                        'epg' => 'EPG',
                    ])
                    ->sortable()
                    ->tooltip('Preferred icon source')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('lang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->numeric()
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stream_id')
                    ->label('Default ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label('Default Title')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->label('Default Name')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('url')
                    ->label('Default URL')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\Filter::make('enabled')
                    ->label('Channel is enabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', true);
                    }),
                Tables\Filters\Filter::make('mapped')
                    ->label('EPG is mapped')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('epg_channel_id', '!=', null);
                    }),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->slideOver()
                        ->form(function (Tables\Actions\EditAction $action): array {
                            $schema = ChannelResource::getForm();
                            // Find and modify the 'name_custom' field
                            foreach ($schema as $key => $component) {
                                if ($component instanceof \Filament\Forms\Components\Fieldset) {
                                    $fieldsetSchema = $component->getChildComponents();
                                    foreach ($fieldsetSchema as $fsKey => $field) {
                                        if ($field instanceof \Filament\Forms\Components\TextInput && $field->getName() === 'name_custom') {
                                            $fieldsetSchema[$fsKey] = $field->rules([
                                                'nullable', // Keep existing behavior of allowing empty to use default
                                                'min:1', // From ChannelResource
                                                'max:255', // From ChannelResource
                                                function () use ($action) {
                                                    return function (string $attribute, $value, \Closure $fail) use ($action) {
                                                        if (empty($value)) { // Do not validate if the custom name is empty
                                                            return;
                                                        }
                                                        // Corrected way to get owner record from an action in a RelationManager
                                                        $ownerPlaylist = $action->getLivewire()->getOwnerRecord(); 
                                                        $channelBeingEditedId = $action->getRecord()->id;

                                                        $query = $ownerPlaylist->channels()
                                                            ->where('channels.id', '!=', $channelBeingEditedId) // Exclude the current channel
                                                            ->where(function ($query) use ($value) {
                                                                $query->where('channels.name_custom', $value)
                                                                      ->orWhere(function($q) use ($value) {
                                                                        // Also check default name if custom name is not set for others
                                                                        $q->whereNull('channels.name_custom')
                                                                          ->where('channels.name', $value);
                                                                      });
                                                            });
                                                        
                                                        // Check if the value matches the default 'name' of the current record if 'name_custom' is being cleared
                                                        // This case is tricky because if $value is now '', we don't want to compare it to other empty custom_names
                                                        // The main concern is if $value (new custom_name) conflicts with an existing name or custom_name

                                                        if ($query->exists()) {
                                                            $fail('A channel with this name (either custom or default) already exists in this playlist.');
                                                        }
                                                    };
                                                }
                                            ]);
                                            // Replace the fieldset with the modified one
                                            $schema[$key] = $component->schema($fieldsetSchema); 
                                            break 2; // Break both loops
                                        }
                                    }
                                } else if ($component instanceof \Filament\Forms\Components\TextInput && $component->getName() === 'name_custom') {
                                    $schema[$key] = $component->rules([
                                        ...$component->getRules(), // Preserve existing rules
                                        function () use ($action) {
                                            return function (string $attribute, $value, \Closure $fail) use ($action) {
                                                if (empty($value)) { // Do not validate if the custom name is empty
                                                    return;
                                                }
                                                // Corrected way to get owner record from an action in a RelationManager
                                                $ownerPlaylist = $action->getLivewire()->getOwnerRecord();
                                                $channelBeingEditedId = $action->getRecord()->id;

                                                $query = $ownerPlaylist->channels()
                                                    ->where('channels.id', '!=', $channelBeingEditedId)
                                                    ->where(function ($query) use ($value) {
                                                        $query->where('channels.name_custom', $value)
                                                              ->orWhere(function($q) use ($value) {
                                                                $q->whereNull('channels.name_custom')
                                                                  ->where('channels.name', $value);
                                                              });
                                                    });

                                                if ($query->exists()) {
                                                    $fail('A channel with this name (either custom or default) already exists in this playlist.');
                                                }
                                            };
                                        }
                                    ]);
                                    break; // Break the loop
                                }
                            }
                            // Ensure the rest of the schema is correctly processed if 'name_custom' isn't found directly or in a fieldset.
                            // However, the current structure of ChannelResource::getForm() places 'name_custom' in a fieldset.

                            return [
                                Forms\Components\Grid::make()
                                    ->schema($schema)
                                    ->columns(2)
                            ];
                        }),
                    Tables\Actions\ViewAction::make()
                        ->slideOver(),
                    Tables\Actions\DetachAction::make()
                ])->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()->color('warning'),
                    Tables\Actions\BulkAction::make('add_to_group')
                        ->label('Add to custom group')
                        ->form([
                            Forms\Components\Select::make('group')
                                ->label('Select group')
                                ->options(
                                    Tag::query()
                                        ->where('type', $ownerRecord->uuid)
                                        ->get()
                                        ->map(fn($name) => [
                                            'id' => $name->getAttributeValue('name'),
                                            'name' => $name->getAttributeValue('name')
                                        ])->pluck('id', 'name')
                                )->required(),
                        ])
                        ->action(function (Collection $records, $data) use ($ownerRecord): void {
                            foreach ($records as $record) {
                                $record->syncTagsWithType([$data['group']], $ownerRecord->uuid);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Added to group')
                                ->body('The selected channels have been added to the custom group.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-squares-plus')
                        ->modalIcon('heroicon-o-squares-plus')
                        ->modalDescription('Add to group')
                        ->modalSubmitActionLabel('Yes, add to group'),
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
                                ->title('Selected channels enabled')
                                ->body('The selected channels have been enabled.')
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
                                ->title('Selected channels disabled')
                                ->body('The selected channels have been disabled.')
                                ->send();
                        })
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ]),
            ]);
    }
}
