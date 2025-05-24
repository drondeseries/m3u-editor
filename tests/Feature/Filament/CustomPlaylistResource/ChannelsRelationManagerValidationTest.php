<?php

namespace Tests\Feature\Filament\CustomPlaylistResource;

use App\Filament\Resources\CustomPlaylistResource;
use App\Models\User;
use App\Models\CustomPlaylist;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChannelsRelationManagerValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected CustomPlaylist $customPlaylist;
    protected Channel $channel1;
    protected Channel $channel2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);

        $this->channel1 = Channel::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Original Name 1', // Default name
            'title' => 'Original Title 1',
        ]);
        $this->channel1->update(['name_custom' => 'Test Channel 1']); // Set custom name

        $this->channel2 = Channel::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Original Name 2', // Default name
            'title' => 'Original Title 2',
        ]);

        $this->customPlaylist->channels()->attach([$this->channel1->id, $this->channel2->id]);
    }

    public function test_cannot_set_duplicate_name_custom_in_channels_relation_manager()
    {
        Livewire::test(CustomPlaylistResource\RelationManagers\ChannelsRelationManager::class, [
                'ownerRecord' => $this->customPlaylist,
                'pageClass' => CustomPlaylistResource\Pages\EditCustomPlaylist::class,
            ])
            ->callTableAction('edit', $this->channel2, data: [
                'name_custom' => 'Test Channel 1', // Attempt to set duplicate custom name
                // Include other required fields from ChannelResource::getForm() if any, 
                // otherwise Filament might complain about missing fields before our validation runs.
                // From ChannelResource::getForm():
                'title_custom' => $this->channel2->title_custom ?? $this->channel2->title, 
                'stream_id_custom' => $this->channel2->stream_id_custom ?? $this->channel2->stream_id,
                'channel' => $this->channel2->channel,
                'shift' => $this->channel2->shift,
                'group_id' => $this->channel2->group_id,
                'logo' => $this->channel2->logo,
                'epg_channel_id' => $this->channel2->epg_channel_id,
                'logo_type' => $this->channel2->logo_type->value ?? $this->channel2->logo_type, // Assuming logo_type is an enum
                'enabled' => $this->channel2->enabled,
            ])
            ->assertHasTableActionErrors(['name_custom' => ['A channel with this name (either custom or default) already exists in this playlist.']]);
    }

    public function test_can_update_channel_with_its_own_existing_name_custom()
    {
        Livewire::test(CustomPlaylistResource\RelationManagers\ChannelsRelationManager::class, [
                'ownerRecord' => $this->customPlaylist,
                'pageClass' => CustomPlaylistResource\Pages\EditCustomPlaylist::class,
            ])
            ->callTableAction('edit', $this->channel1, data: [
                'name_custom' => 'Test Channel 1', // Update with its current custom name
                'title_custom' => $this->channel1->title_custom ?? $this->channel1->title,
                'stream_id_custom' => $this->channel1->stream_id_custom ?? $this->channel1->stream_id,
                'channel' => $this->channel1->channel,
                'shift' => $this->channel1->shift,
                'group_id' => $this->channel1->group_id,
                'logo' => $this->channel1->logo,
                'epg_channel_id' => $this->channel1->epg_channel_id,
                'logo_type' => $this->channel1->logo_type->value ?? $this->channel1->logo_type,
                'enabled' => $this->channel1->enabled,
            ])
            ->assertHasNoTableActionErrors();
    }

    public function test_cannot_set_name_custom_that_matches_another_channels_default_name()
    {
        // Setup: channel1 has 'Original Name 1' as its default 'name'
        // channel2 will try to set its 'name_custom' to 'Original Name 1'
        $channel3 = Channel::factory()->create([ // The channel whose name_custom will be updated
            'user_id' => $this->user->id,
            'name' => 'Unique Default Name 3',
            'title' => 'Title 3',
        ]);
        $this->customPlaylist->channels()->attach($channel3->id);
        
        // Ensure channel1's name_custom is null so its default 'name' is considered
        $this->channel1->update(['name_custom' => null]);
        $this->channel1->refresh(); // refresh to ensure model state is current
        $this->assertEquals('Original Name 1', $this->channel1->name);
        $this->assertNull($this->channel1->name_custom);

        Livewire::test(CustomPlaylistResource\RelationManagers\ChannelsRelationManager::class, [
                'ownerRecord' => $this->customPlaylist,
                'pageClass' => CustomPlaylistResource\Pages\EditCustomPlaylist::class,
            ])
            ->callTableAction('edit', $channel3, data: [
                'name_custom' => 'Original Name 1', // Attempt to set to channel1's default name
                'title_custom' => $channel3->title_custom ?? $channel3->title,
                'stream_id_custom' => $channel3->stream_id_custom ?? $channel3->stream_id,
                'channel' => $channel3->channel,
                'shift' => $channel3->shift,
                'group_id' => $channel3->group_id,
                'logo' => $channel3->logo,
                'epg_channel_id' => $channel3->epg_channel_id,
                'logo_type' => $channel3->logo_type->value ?? $channel3->logo_type,
                'enabled' => $channel3->enabled,
            ])
            ->assertHasTableActionErrors(['name_custom' => ['A channel with this name (either custom or default) already exists in this playlist.']]);
    }
}
