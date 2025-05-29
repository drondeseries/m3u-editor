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

    /** @test */
    public function it_auto_creates_default_profile_if_none_exist()
    {
        Event::fake();
        $playlist = Playlist::factory()->create();

        $this->assertEquals(0, $playlist->playlistProfiles()->count()); // Ensure no profiles exist initially

        $retrievedProfile = $playlist->defaultProfile();

        $this->assertNotNull($retrievedProfile);
        $this->assertEquals("Default Profile", $retrievedProfile->name);
        $this->assertEquals(1, $retrievedProfile->max_streams);
        $this->assertTrue($retrievedProfile->is_default);
        $this->assertTrue($retrievedProfile->is_active);
        $this->assertEquals($playlist->id, $retrievedProfile->playlist_id);
        $this->assertDatabaseHas('playlist_profiles', [
            'id' => $retrievedProfile->id,
            'playlist_id' => $playlist->id,
            'name' => 'Default Profile',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->assertEquals(1, $playlist->playlistProfiles()->count()); // Ensure one profile was created
    }

    /** @test */
    public function it_auto_creates_default_profile_if_existing_default_is_inactive()
    {
        Event::fake();
        $playlist = Playlist::factory()->create();

        $inactiveDefaultProfile = PlaylistProfile::factory()
            ->for($playlist)
            ->isDefault()
            ->inactive()
            ->create();

        $this->assertEquals(1, $playlist->playlistProfiles()->count());

        $retrievedProfile = $playlist->defaultProfile();

        $this->assertNotNull($retrievedProfile);
        $this->assertNotEquals($inactiveDefaultProfile->id, $retrievedProfile->id);
        $this->assertEquals("Default Profile", $retrievedProfile->name);
        $this->assertTrue($retrievedProfile->is_default);
        $this->assertTrue($retrievedProfile->is_active);
        $this->assertEquals($playlist->id, $retrievedProfile->playlist_id);

        $this->assertDatabaseHas('playlist_profiles', ['id' => $retrievedProfile->id, 'is_active' => true]);
        $this->assertDatabaseHas('playlist_profiles', ['id' => $inactiveDefaultProfile->id, 'is_active' => false]);
        $this->assertEquals(2, $playlist->playlistProfiles()->count());
    }

    /** @test */
    public function it_auto_creates_default_profile_if_existing_active_is_not_default()
    {
        Event::fake();
        $playlist = Playlist::factory()->create();

        $activeNonDefaultProfile = PlaylistProfile::factory()
            ->for($playlist)
            ->create(['is_default' => false, 'is_active' => true]);

        $this->assertEquals(1, $playlist->playlistProfiles()->count());

        $retrievedProfile = $playlist->defaultProfile();

        $this->assertNotNull($retrievedProfile);
        $this->assertNotEquals($activeNonDefaultProfile->id, $retrievedProfile->id);
        $this->assertEquals("Default Profile", $retrievedProfile->name);
        $this->assertTrue($retrievedProfile->is_default);
        $this->assertTrue($retrievedProfile->is_active);
        $this->assertEquals($playlist->id, $retrievedProfile->playlist_id);

        $this->assertDatabaseHas('playlist_profiles', ['id' => $retrievedProfile->id, 'is_default' => true, 'is_active' => true]);
        $this->assertDatabaseHas('playlist_profiles', ['id' => $activeNonDefaultProfile->id, 'is_default' => false]);
        $this->assertEquals(2, $playlist->playlistProfiles()->count());
    }
}
