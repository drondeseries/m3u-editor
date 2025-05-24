<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use App\Models\CustomPlaylist;
use App\Models\Channel;
use App\Models\MergedChannel;
use App\Enums\PlaylistChannelId; // Added this line
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlaylistGenerateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_hdhr_lineup_includes_direct_and_merged_channels()
    {
        // Setup
        $user = User::factory()->create();
        $this->actingAs($user);

        // Ensure a highly unique UUID for the CustomPlaylist under test
        $customPlaylistUuid = 'test_custom_playlist_' . \Illuminate\Support\Str::uuid()->toString();
        $customPlaylist = CustomPlaylist::factory()->create([
            'uuid' => $customPlaylistUuid,
            'user_id' => $user->id,
            'id_channel_by' => PlaylistChannelId::TvgId->value, // Corrected Enum case
        ]);

        // Create and attach regular channels
        $regularChannel1 = Channel::factory()->create([
            'user_id' => $user->id,
            'title' => 'Regular Channel 1 Title',
            'name' => 'Regular Channel 1 Name',
            'stream_id' => 'reg1_streamid', // Used for default GuideNumber
        ]);
        $regularChannel2 = Channel::factory()->create([
            'user_id' => $user->id,
            'title' => 'Regular Channel 2 Title',
            'name' => 'Regular Channel 2 Name',
            'stream_id' => 'reg2_streamid',
        ]);
        $customPlaylist->channels()->attach([$regularChannel1->id, $regularChannel2->id]);

        // Create and attach merged channels
        // Ensure these are as simple and identical as possible beyond what's needed for differentiation
        $mergedChannel1 = MergedChannel::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Merged Channel Alpha', 
        ]);
        $mergedChannel2 = MergedChannel::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Merged Channel Bravo', 
        ]);
        // Try with sync instead of attach
        $customPlaylist->mergedChannels()->sync([$mergedChannel1->id, $mergedChannel2->id]);

        // Diagnostic assertion: Check if both merged channels are associated in the test context
        $this->assertEquals(2, $customPlaylist->mergedChannels()->count(), "CustomPlaylist should have 2 merged channels after attach.");

        // Test Action
        $response = $this->get(route('playlist.hdhr.lineup', ['uuid' => $customPlaylist->uuid]));

        // Assertions
        $response->assertStatus(200);
        $response->assertJsonCount(4); // 2 regular + 2 merged

        $jsonResponse = $response->json();

        // Check for regular channel 1
        $this->assertContainsChannel($jsonResponse, [
            'GuideNumber' => (string) $regularChannel1->stream_id_custom ?? $regularChannel1->stream_id,
            'GuideName' => $regularChannel1->title_custom ?? $regularChannel1->title,
            // URL check can be brittle if proxy logic changes, so focusing on GuideNumber/Name
        ]);

        // Check for regular channel 2
        $this->assertContainsChannel($jsonResponse, [
            'GuideNumber' => (string) $regularChannel2->stream_id_custom ?? $regularChannel2->stream_id,
            'GuideName' => $regularChannel2->title_custom ?? $regularChannel2->title,
        ]);

        // Check for merged channel 1
        $this->assertContainsChannel($jsonResponse, [
            'GuideNumber' => 'merged_' . $mergedChannel1->id,
            'GuideName' => 'Test Merged Channel Alpha',
            'URL' => route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel1->id, 'format' => 'ts']),
        ]);

        // Check for merged channel 2
        $this->assertContainsChannel($jsonResponse, [
            'GuideNumber' => 'merged_' . $mergedChannel2->id,
            'GuideName' => 'Test Merged Channel Bravo',
            'URL' => route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel2->id, 'format' => 'ts']),
        ]);
    }

    /**
     * Helper function to assert that a channel exists in the lineup.
     */
    protected function assertContainsChannel(array $lineup, array $expectedChannel)
    {
        $found = false;
        foreach ($lineup as $channel) {
            if ($channel['GuideNumber'] === $expectedChannel['GuideNumber'] &&
                $channel['GuideName'] === $expectedChannel['GuideName']) {
                if (isset($expectedChannel['URL'])) {
                    if ($channel['URL'] === $expectedChannel['URL']) {
                        $found = true;
                        break;
                    }
                } else {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, "Channel not found or URL mismatch: " . print_r($expectedChannel, true));
    }
}
