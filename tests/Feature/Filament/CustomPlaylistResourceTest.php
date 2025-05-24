<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomPlaylistResource\Pages\EditCustomPlaylist;
use App\Models\User;
use App\Models\CustomPlaylist;
use App\Models\MergedChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomPlaylistResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected CustomPlaylist $customPlaylist;
    protected MergedChannel $mergedChannel1;
    protected MergedChannel $mergedChannel2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
        $this->mergedChannel1 = MergedChannel::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Merged Channel Alpha',
        ]);
        $this->mergedChannel2 = MergedChannel::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Merged Channel Bravo',
        ]);
    }

    public function test_can_attach_and_detach_merged_channels_in_custom_playlist_form()
    {
        $livewireTest = Livewire::test(EditCustomPlaylist::class, [
            'record' => $this->customPlaylist->getRouteKey(),
        ]);

        // --- Test Attach Action ---
        // Mount the repeater header action, fill its form, then call it.
        $livewireTest
            ->mountAction('attach_merged_channels_action', 'header', 'mergedChannels') // Mount header action of repeater
            ->assertActionVisible('attach_merged_channels_action', 'header', 'mergedChannels') // Ensure modal is 'open'
            ->setMountedActionForm(['merged_channel_ids_to_attach' => [$this->mergedChannel1->id]])
            ->callMountedAction()
            ->assertHasNoErrors(); // Check if modal action executed without validation errors

        $this->customPlaylist->refresh();
        $this->assertTrue($this->customPlaylist->mergedChannels->contains($this->mergedChannel1->id));
        $this->assertEquals(1, $this->customPlaylist->mergedChannels()->count());

        // --- Test Repeater State (Post-Attach) ---
        // After the action, the main form's data should be updated.
        // The repeater state 'data.mergedChannels' should reflect the attached item.
        // Filament keys items in a relationship repeater by the related model's ID.
        $livewireTest->assertCount('data.mergedChannels', 1);
        // Check if the specific item corresponding to mergedChannel1 is in the repeater's state.
        // The state for each item in a relationship repeater usually contains the attributes of the related model.
        $this->assertArrayHasKey((string)$this->mergedChannel1->id, $livewireTest->getData('data.mergedChannels'));


        // --- Test Detach Action ---
        // The repeater item key for BelongsToMany is the related model's ID.
        // The default action for 'deletable()' on a repeater item is 'delete'.
        $livewireTest->callRepeaterItemAction('mergedChannels', (string)$this->mergedChannel1->id, 'delete')
            ->assertHasNoErrors(); // Check if detach action executed

        // The form data should update immediately after the detach action.
        $livewireTest->assertCount('data.mergedChannels', 0);
        
        // The database should not reflect the change until 'save' is called.
        // However, Filament's Repeater with ->relationship() often syncs changes immediately for BelongsToMany.
        // Let's check the database state to confirm the behavior.
        $this->customPlaylist->refresh();
        $this->assertFalse($this->customPlaylist->mergedChannels->contains($this->mergedChannel1->id), "Merged channel should be detached from DB after repeater delete action with relationship.");
        $this->assertEquals(0, $this->customPlaylist->mergedChannels()->count());


        // --- Save the Form ---
        // Call the main save action of the edit page.
        $livewireTest->callPageAction('save') // Default save action name
            ->assertHasNoPageActionErrors();

        $this->customPlaylist->refresh(); // Refresh from DB
        $this->assertEquals(0, $this->customPlaylist->mergedChannels()->count(), "DB count should be 0 after save.");
    }
}
