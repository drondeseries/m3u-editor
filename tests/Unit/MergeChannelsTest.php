<?php

namespace Tests\Unit;

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MergeChannelsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_does_not_merge_channels_with_empty_stream_ids()
    {
        // Create a user
        $user = User::factory()->create();

        // Create channels
        $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id]);
        $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id]);
        $channel3 = Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id]);
        $channel4 = Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id]);

        $channels = new Collection([$channel1, $channel2, $channel3, $channel4]);

        // Dispatch the job
        (new MergeChannels($channels->pluck('id'), $user))->handle();

        // Assert that only the channels with the same stream_id were merged
        $this->assertDatabaseCount('channel_failovers', 1);
    }

    /** @test */
    public function it_merges_channels_based_on_preferred_playlist()
    {
        // Create a user
        $user = User::factory()->create();

        // Create playlists
        $playlist1 = \App\Models\Playlist::factory()->create(['user_id' => $user->id]);
        $playlist2 = \App\Models\Playlist::factory()->create(['user_id' => $user->id]);

        // Create channels
        $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist1->id]);
        $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist2->id]);
        $channel3 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist2->id]);

        $channels = new Collection([$channel1, $channel2, $channel3]);

        // Dispatch the job with playlist1 as preferred
        (new MergeChannels($channels->pluck('id'), $user, $playlist1->id))->handle();

        // Assert that channel1 is the master
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $channel1->id,
            'channel_failover_id' => $channel2->id,
        ]);
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $channel1->id,
            'channel_failover_id' => $channel3->id,
        ]);

        // Assert that there are no other failovers
        $this->assertDatabaseCount('channel_failovers', 2);
    }
}
