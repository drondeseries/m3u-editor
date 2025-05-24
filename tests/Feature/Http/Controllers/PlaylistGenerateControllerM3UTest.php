<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// Helper to create a MergedChannel with source channels
function createMergedChannelForM3UTest(User $user, string $name, array $sourcesData): MergedChannel
{
    $mergedChannel = MergedChannel::factory()->for($user)->create(['name' => $name]);
    foreach ($sourcesData as $source) {
        // For M3U generation, the actual URL of source channels isn't directly tested,
        // but creating them for data integrity.
        $channel = Channel::factory()->for($user)->create(['url' => $source['url'] ?? 'http://example.com/source']);
        $mergedChannel->sourceChannels()->attach($channel->id, ['priority' => $source['priority']]);
    }
    return $mergedChannel;
}

test('TestCase 1: CustomPlaylist with MergedChannel in M3U', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // 1. Create CustomPlaylist
    $customPlaylist = CustomPlaylist::factory()->for($user)->create([
        'name' => 'My Custom M3U Test Playlist',
        'enable_proxy' => false, // Assuming direct stream URLs for merged channels for simplicity
    ]);

    // 2. Create MergedChannel with source(s)
    $mergedChannel = createMergedChannelForM3UTest($user, 'Test Merged Stream 1', [
        ['url' => 'http://source1.test/live.m3u8', 'priority' => 0],
    ]);

    // 3. Associate MergedChannel with CustomPlaylist
    $customPlaylist->mergedChannels()->attach($mergedChannel->id);

    // 4. Access M3U generation route
    $response = $this->get(route('playlist.generate', ['uuid' => $customPlaylist->uuid]));

    // 5. Assertions
    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');

    $m3uContent = $response->streamedContent();

    // Assert that the M3U output contains an entry for the MergedChannel
    $this->assertStringContainsString("#EXTINF:-1 tvg-id=\"mergedchannel_{$mergedChannel->id}\" tvg-name=\"{$mergedChannel->name}\"", $m3uContent);
    
    // Assert that the stream URL for this MergedChannel points to the mergedChannel.stream route
    // We need to generate the expected URL. The route() helper might not work exactly the same
    // outside a request context in tests for all drivers, so constructing it carefully.
    $expectedStreamUrl = route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts']);
    $this->assertStringContainsString($expectedStreamUrl, $m3uContent);
    
    // Assert tvg-logo and group-title (as per PlaylistGenerateController logic)
    $this->assertStringContainsString("tvg-logo=\"" . url('/placeholder.png') . "\"", $m3uContent);
    $this->assertStringContainsString("group-title=\"Merged Channels\"", $m3uContent);
    $this->assertStringContainsString(",{$mergedChannel->name}\n{$expectedStreamUrl}", $m3uContent);

});
