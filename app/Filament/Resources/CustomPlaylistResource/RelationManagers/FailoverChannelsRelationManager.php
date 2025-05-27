<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\FailoverChannel; // Ensure this is imported

class FailoverChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'failoverChannels';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        // For now, keep it simple. Direct editing of FailoverChannel properties
        // via this relation manager is not the primary goal.
        // A more complex form could be built here if needed, similar to FailoverChannelResource.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_display')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tvg_channel_number_display')
                    ->label('TVG ChNo')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sources_count')
                    ->label('Sources')
                    ->counts('sources') // This will automatically query the count of the 'sources' relationship
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                // Add any relevant filters here if needed
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect(),
                // Potentially Tables\Actions\CreateAction::make() if creating new FailoverChannels directly from here is desired
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
                Tables\Actions\Action::make('edit_failover_channel')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square') // Corrected icon name
                    ->url(fn ($record): string => \App\Filament\Resources\FailoverChannelResource::getUrl('edit', ['record' => $record]))
                    // ->openUrlInNewTab(), // Optional
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                    // Potentially Tables\Actions\DeleteBulkAction::make(), // If hard deletion from this manager is desired
                ]),
            ]);
    }
}
