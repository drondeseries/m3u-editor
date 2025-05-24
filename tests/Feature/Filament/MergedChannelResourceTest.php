<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MergedChannelResource\Pages;
use App\Models\User;
use App\Models\Channel;
use App\Models\MergedChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MergedChannelResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_cannot_create_merged_channel_with_empty_name()
    {
        // A channel is needed for the sourceChannels repeater
        $sourceChannel = Channel::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(Pages\CreateMergedChannel::class)
            ->fillForm([
                'name' => '',
                'epg_channel_id' => null, // epg_channel_id is nullable
                'sourceChannels' => [ // Repeater needs at least one item with a valid channel_id
                    [
                        'source_channel_id' => $sourceChannel->id,
                        'priority' => 0,
                    ],
                ],
            ])
            ->call('create') // Corrected: Call the 'create' method on the Livewire component
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertEquals(0, MergedChannel::where('name', '')->count());
        $this->assertEquals(0, MergedChannel::where('user_id', $this->user->id)->count());
    }

    public function test_cannot_update_merged_channel_to_have_empty_name()
    {
        $sourceChannel = Channel::factory()->create(['user_id' => $this->user->id]);
        $mergedChannel = MergedChannel::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Original Valid Name',
        ]);
        // Attach an initial source channel to make the record valid for editing context
        $mergedChannel->sourceChannels()->attach($sourceChannel->id, ['priority' => 0]);
        // $mergedChannel->refresh(); // Not strictly necessary here as fillForm doesn't rely on it for sourceChannels if provided

        // Prepare sourceChannels data in the format the repeater expects for fillForm
        $existingSourceChannelsData = $mergedChannel->sourceEntries->map(function ($entry) {
            return [
                // 'id' => $entry->id, // Repeater items usually don't need existing pivot IDs directly in fillForm
                'source_channel_id' => $entry->source_channel_id,
                'priority' => $entry->priority,
            ];
        })->toArray();
        
        Livewire::test(Pages\EditMergedChannel::class, [
            'record' => $mergedChannel->getRouteKey(),
        ])
            ->fillForm([
                'name' => '',
                'epg_channel_id' => $mergedChannel->epg_channel_id, // Preserve existing
                'sourceChannels' => $existingSourceChannelsData, // Preserve existing
            ])
            ->call('save') // Corrected: Call the 'save' method on the Livewire component
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertDatabaseHas('merged_channels', [
            'id' => $mergedChannel->id,
            'name' => 'Original Valid Name',
        ]);
        $this->assertEquals('Original Valid Name', $mergedChannel->refresh()->name);
    }
}
