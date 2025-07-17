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
    public function it_merges_multiple_channels_with_the_same_id()
    {
        $user = User::factory()->create();
        $primaryPlaylist = \App\Models\Playlist::factory()->create(['user_id' => $user->id]);
        $failoverPlaylist1 = \App\Models\Playlist::factory()->create(['user_id' => $user->id]);
        $failoverPlaylist2 = \App\Models\Playlist::factory()->create(['user_id' => $user->id]);

        $primaryChannel = Channel::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $primaryPlaylist->id,
            'stream_id' => 'test_stream_1',
        ]);

        $failoverChannel1 = Channel::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $failoverPlaylist1->id,
            'stream_id' => 'test_stream_1',
        ]);

        $failoverChannel2 = Channel::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $failoverPlaylist2->id,
            'stream_id' => 'test_stream_1',
        ]);

        $playlists = new Collection([$failoverPlaylist1->id, $failoverPlaylist2->id]);

        $job = new MergeChannels($user, $playlists, $primaryPlaylist->id);
        $job->handle();

        $this->assertDatabaseCount('channel_failovers', 2);
    }
}
