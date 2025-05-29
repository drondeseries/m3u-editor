<?php

namespace Tests\Unit;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Event; // Required for Event::fake()

class PlaylistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Redis to prevent actual connections, as done in previous tests
        // to avoid side effects from listeners or other Redis interactions.
        if (class_exists(\Illuminate\Contracts\Redis\Factory::class)) {
            $redisMock = $this->getMockBuilder(\Illuminate\Contracts\Redis\Factory::class)
                ->disableOriginalConstructor()
                ->getMock();
            $this->app->instance('redis', $redisMock);
        }
    }

    /** @test */
    public function it_retrieves_the_default_active_profile()
    {
        Event::fake(); // Prevent any events from firing, similar to other tests

        // 1. Create a Playlist instance
        $playlist = Playlist::factory()->create();

        // 2. Create a default, active PlaylistProfile
        $defaultProfile = PlaylistProfile::factory()->for($playlist)->isDefault()->create([
            'is_active' => true,
        ]);

        // 3. Create another non-default profile for the same playlist (optional)
        PlaylistProfile::factory()->for($playlist)->create([
            'is_default' => false,
            'is_active' => true,
        ]);

        // Create an inactive default profile to ensure it's not picked
         PlaylistProfile::factory()->for($playlist)->isDefault()->inactive()->create();


        // 4. Call the defaultProfile() method
        $retrievedProfile = $playlist->defaultProfile();

        // 5. Assert that the returned PlaylistProfile is not null
        $this->assertNotNull($retrievedProfile);

        // 6. Assert that the ID of the returned PlaylistProfile matches the ID of the default, active one
        $this->assertEquals($defaultProfile->id, $retrievedProfile->id);

        // 7. Assert that the is_default property of the returned profile is true
        $this->assertTrue($retrievedProfile->is_default);

        // 8. Assert that the is_active property of the returned profile is true
        $this->assertTrue($retrievedProfile->is_active);
    }
}
