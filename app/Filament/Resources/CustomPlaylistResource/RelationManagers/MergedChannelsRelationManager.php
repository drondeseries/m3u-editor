<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Filament\Resources\MergedChannelResource;
use App\Models\MergedChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
// Ensure HtmlString is imported if ever needed for complex content, though not directly in this version.
// use Illuminate\Support\HtmlString; 

class MergedChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'mergedChannels';

    public function form(Form $form): Form
    {
        return $form->schema([]); // No fields for direct edit in table row for now
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name') 
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (MergedChannel $record): string => MergedChannelResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('tvg_chno')
                    ->label('TVG ChNo')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tvg_id')
                    ->label('TVG ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tvg_logo')
                    ->label('TVG Logo URL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (MergedChannel $record): ?string => $record->tvg_logo) // Show full URL on hover
                    ->limit(30), // Limit displayed length
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Source')
                    ->searchable()
                    ->sortable()
                    ->placeholder('N/A'),
                Tables\Columns\TextColumn::make('sourceChannels_count')
                    ->counts('sourceChannels')
                    ->label('Sources')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stream_url')
                    ->label('Stream URL')
                    ->getStateUsing(fn (MergedChannel $record): string => route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'ts']))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip(fn (MergedChannel $record): string => route('mergedChannel.stream', ['mergedChannelId' => $record->id, 'format' => 'ts']))
                    ->limit(30), // Limit displayed length
            ])
            ->filters([
                // Define any relevant filters here if needed in the future
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn (MergedChannel $record): string => MergedChannelResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
