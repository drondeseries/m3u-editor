<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Channel;
use App\Models\FailoverChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class ChannelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis to prevent actual connections
        $redisMock = $this->getMockBuilder(\Illuminate\Contracts\Redis\Factory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->app->instance('redis', $redisMock);
    }

    /** @test */
    public function it_can_have_failover_channels()
    {
        Event::fake();

        // 1. Set up the necessary data
        $channel = Channel::factory()->create();
        $failoverChannel1 = FailoverChannel::factory()->create();
        $failoverChannel2 = FailoverChannel::factory()->create();

        // Attach FailoverChannel instances to the Channel instance
        $channel->failover_channels()->attach([
            $failoverChannel1->id => ['order' => 1],
            $failoverChannel2->id => ['order' => 2],
        ]);

        // 2. Retrieve the related FailoverChannel instances
        $retrievedFailoverChannels = $channel->failover_channels;

        // 3. Assert that the retrieved collection of FailoverChannel instances is not empty
        $this->assertNotEmpty($retrievedFailoverChannels);

        // 4. Assert that the count of the retrieved FailoverChannel instances matches the number of FailoverChannel instances that were attached
        $this->assertCount(2, $retrievedFailoverChannels);

        // 5. Assert that the IDs of the retrieved FailoverChannel instances match the IDs of the FailoverChannel instances that were attached
        $this->assertEqualsCanonicalizing(
            [$failoverChannel1->id, $failoverChannel2->id],
            $retrievedFailoverChannels->pluck('id')->all()
        );
    }
}
