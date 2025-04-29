<?php

namespace App\Filament\Resources\PlaylistSyncStatusResource\RelationManagers;

use App\Models\PlaylistSyncStatusLog;
use Filament\Forms;
use Filament\Resources\Components\Tab;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sync Logs')
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Item Name')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->colors([
                        'primary',
                        'primary' => 'channel',
                        'gray' => 'group',
                    ])
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'primary',
                        'success' => 'added',
                        'danger' => 'removed',
                    ])
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                // Tables\Filters\Filter::make('added')
                //     ->label('Item is added')
                //     ->toggle()
                //     ->query(function ($query) {
                //         return $query->where('status', 'added');
                //     }),
                // Tables\Filters\Filter::make('removed')
                //     ->label('Item is removed')
                //     ->toggle()
                //     ->query(function ($query) {
                //         return $query->where('status', 'removed');
                //     }),
                // Tables\Filters\Filter::make('channels')
                //     ->label('Channels only')
                //     ->toggle()
                //     ->query(function ($query) {
                //         return $query->where('type', 'channel');
                //     }),
                // Tables\Filters\Filter::make('groups')
                //     ->label('Groups only')
                //     ->toggle()
                //     ->query(function ($query) {
                //         return $query->where('type', 'group');
                //     }),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs(): array
    {
        // Change count based on view
        $addedChannels = PlaylistSyncStatusLog::query()
            ->where([
                'type' => 'channel',
                'status' => 'added',
            ])->count();
        $removedChannels = PlaylistSyncStatusLog::query()
            ->where([
                'type' => 'channel',
                'status' => 'removed',
            ])->count();
        $addedGroups = PlaylistSyncStatusLog::query()
            ->where([
                'type' => 'group',
                'status' => 'added',
            ])->count();
        $removedGroups = PlaylistSyncStatusLog::query()
            ->where([
                'type' => 'group',
                'status' => 'removed',
            ])->count();

        // Return tabs
        return [
            'added_channels' => Tab::make('Added Channels')
                ->badge($addedChannels)
                ->badgeColor('success')
                ->modifyQueryUsing(fn($query) => $query->where([
                    'type' => 'channel',
                    'status' => 'added',
                ])),
            'removed_channels' => Tab::make('Removed Channels')
                ->badge($removedChannels)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where([
                    'type' => 'channel',
                    'status' => 'removed',
                ])),
            'added_groups' => Tab::make('Added Groups')
                ->badge($addedGroups)
                ->badgeColor('success')
                ->modifyQueryUsing(fn($query) => $query->where([
                    'type' => 'group',
                    'status' => 'added',
                ])),
            'removed_groups' => Tab::make('Removed Groups')
                ->badge($removedGroups)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where([
                    'type' => 'group',
                    'status' => 'removed',
                ])),
        ];
    }
}
