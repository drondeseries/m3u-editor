<?php

use App\Filament\Resources\MergedChannelResource;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\MergedChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Helper to create a MergedChannel with basic data for listing and editing
function createBasicMergedChannel(User $user, string $name = 'Test Merged Channel'): MergedChannel
{
    return MergedChannel::factory()->for($user)->create(['name' => $name]);
}

// Helper to create source channels for a merged channel
function addSourcesToMergedChannel(MergedChannel $mergedChannel, User $user, array $sourcesData): void
{
    foreach ($sourcesData as $source) {
        $channel = Channel::factory()->for($user)->create(['name' => $source['name'], 'url' => $source['url']]);
        $mergedChannel->sourceChannels()->attach($channel->id, ['priority' => $source['priority']]);
    }
}

test('TestCase 1: List MergedChannels', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $mergedChannel1 = createBasicMergedChannel($user, 'Merged Alpha');
    $mergedChannel2 = createBasicMergedChannel($user, 'Merged Beta');
    $otherUser = User::factory()->create();
    createBasicMergedChannel($otherUser, 'Other User Merged'); // Should not be listed

    Livewire::test(MergedChannelResource\Pages\ListMergedChannels::class)
        ->assertCanSeeTableRecords([$mergedChannel1, $mergedChannel2])
        ->assertCanNotSeeTableRecords([createBasicMergedChannel($otherUser, 'Other User Merged Invisible')]);
});

test('TestCase 2: Create MergedChannel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $sourceChannel1 = Channel::factory()->for($user)->create(['name' => 'Source TV 1']);
    $sourceChannel2 = Channel::factory()->for($user)->create(['name' => 'Source TV 2']);
    $epgChannel = EpgChannel::factory()->create(['name' => 'EPG Source A']); // Assuming EpgChannel factory exists or create one simply

    $newMergedChannelName = 'My New Merged Channel';

    Livewire::test(MergedChannelResource\Pages\CreateMergedChannel::class)
        ->fillForm([
            'name' => $newMergedChannelName,
            'epg_channel_id' => $epgChannel->id,
            'sourceChannels' => [
                // Repeater items are typically keyed by a temporary ID, e.g., 'record-12345'
                // For creation, we send an array of arrays for the repeater data.
                // The keys inside each inner array should match the `make()` definitions in the Repeater schema.
                [
                    'source_channel_id' => $sourceChannel1->id,
                    'priority' => 0,
                ],
                [
                    'source_channel_id' => $sourceChannel2->id,
                    'priority' => 1,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('merged_channels', [
        'name' => $newMergedChannelName,
        'user_id' => $user->id,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $createdMergedChannel = MergedChannel::where('name', $newMergedChannelName)->first();
    $this->assertNotNull($createdMergedChannel);

    $this->assertDatabaseHas('merged_channel_sources', [
        'merged_channel_id' => $createdMergedChannel->id,
        'source_channel_id' => $sourceChannel1->id,
        'priority' => 0,
    ]);
    $this->assertDatabaseHas('merged_channel_sources', [
        'merged_channel_id' => $createdMergedChannel->id,
        'source_channel_id' => $sourceChannel2->id,
        'priority' => 1,
    ]);
});

test('TestCase 3: Edit MergedChannel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $mergedChannel = createBasicMergedChannel($user, 'Original Name');
    $initialSource1 = Channel::factory()->for($user)->create(['name' => 'Initial Source 1', 'url' => 'http://initial1.test']);
    $initialSource2 = Channel::factory()->for($user)->create(['name' => 'Initial Source 2', 'url' => 'http://initial2.test']);
    addSourcesToMergedChannel($mergedChannel, $user, [
        ['name' => 'Initial Source 1', 'url' => 'http://initial1.test', 'priority' => 0],
        ['name' => 'Initial Source 2', 'url' => 'http://initial2.test', 'priority' => 1],
    ]);
    
    // Get the actual IDs of the pivot records if possible, or rely on the repeater's existing data structure
    // For this test, we'll reconstruct the repeater data with potentially new/modified items.
    // Filament's Repeater handles existing items by their keys if they are passed back.

    $newSource = Channel::factory()->for($user)->create(['name' => 'New Source 3', 'url' => 'http://new3.test']);
    $newEpg = EpgChannel::factory()->create(['name' => 'New EPG Source']);
    $updatedName = 'Updated Merged Channel Name';

    // Refetching source channels to ensure we have their IDs as stored in pivot
    $mcv = $mergedChannel->load('sourceChannels');
    $sourceChannelId1 = $mcv->sourceChannels()->where('name', 'Initial Source 1')->first()->id;
    $sourceChannelId2 = $mcv->sourceChannels()->where('name', 'Initial Source 2')->first()->id;


    Livewire::test(MergedChannelResource\Pages\EditMergedChannel::class, [
        'record' => $mergedChannel->getRouteKey(),
    ])
    ->assertFormSet([ // Check if initial data is loaded correctly
        'name' => 'Original Name',
        // 'sourceChannels' data is more complex to assert directly due to repeater keys
    ])
    ->fillForm([
        'name' => $updatedName,
        'epg_channel_id' => $newEpg->id,
        'sourceChannels' => [
            // Item 1 (existing, priority change) - Use actual source_channel_id
            [
                'source_channel_id' => $sourceChannelId1, // Keep existing source 1
                'priority' => 1, // Change priority
            ],
            // Item 2 (new source)
            [
                'source_channel_id' => $newSource->id,
                'priority' => 0, // New highest priority
            ],
            // Initial Source 2 is effectively removed by not including it here
        ],
    ])
    ->call('save')
    ->assertHasNoFormErrors();

    $this->assertDatabaseHas('merged_channels', [
        'id' => $mergedChannel->id,
        'name' => $updatedName,
        'epg_channel_id' => $newEpg->id,
    ]);

    // Verify updated source 1
    $this->assertDatabaseHas('merged_channel_sources', [
        'merged_channel_id' => $mergedChannel->id,
        'source_channel_id' => $sourceChannelId1,
        'priority' => 1,
    ]);

    // Verify new source 3
    $this->assertDatabaseHas('merged_channel_sources', [
        'merged_channel_id' => $mergedChannel->id,
        'source_channel_id' => $newSource->id,
        'priority' => 0,
    ]);
    
    // Verify initial source 2 was removed
    $this->assertDatabaseMissing('merged_channel_sources', [
        'merged_channel_id' => $mergedChannel->id,
        'source_channel_id' => $sourceChannelId2,
    ]);
});

test('TestCase 4: Delete MergedChannel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $mergedChannel = createBasicMergedChannel($user, 'To Be Deleted');
    $sourceChannel = Channel::factory()->for($user)->create(['name' => 'Source For Deleted', 'url' => 'http://delete.test']);
    addSourcesToMergedChannel($mergedChannel, $user, [
        ['name' => 'Source For Deleted', 'url' => 'http://delete.test', 'priority' => 0],
    ]);
    
    $mergedChannelId = $mergedChannel->id;
    $sourcePivotId = $mergedChannel->sourceChannels()->first()->pivot->id; // Assuming MergedChannelSource uses id as primary key for pivot

    Livewire::test(MergedChannelResource\Pages\EditMergedChannel::class, [ // Deletion is often on Edit or List page
        'record' => $mergedChannel->getRouteKey(),
    ])
    ->callPageAction(Filament\Actions\DeleteAction::class); // Or ->callTableAction if on List page

    $this->assertDatabaseMissing('merged_channels', [
        'id' => $mergedChannelId,
    ]);
    // Also assert that related pivot records are deleted (due to cascade on delete or model events)
    $this->assertDatabaseMissing('merged_channel_sources', [
        'merged_channel_id' => $mergedChannelId,
    ]);
});

test('TestCase 5: Authorization', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $mergedChannelA = createBasicMergedChannel($userA, 'Channel User A');

    // User B attempts to list User A's channels
    $this->actingAs($userB);
    Livewire::test(MergedChannelResource\Pages\ListMergedChannels::class)
        ->assertCanNotSeeTableRecords([$mergedChannelA]);

    // User B attempts to access User A's channel edit page
    // Filament typically handles this with a 403 or 404 if the query is scoped
    $response = $this->get(MergedChannelResource::getUrl('edit', ['record' => $mergedChannelA]));
    // Depending on how Filament handles unauthorized access to an edit page for a scoped resource,
    // it might be a 404 (because the record is not found by the scoped query) or a 403.
    // Given our getEloquentQuery() scopes by user_id, a 404 is more likely for the Edit page.
    $response->assertStatus(404); 
});
