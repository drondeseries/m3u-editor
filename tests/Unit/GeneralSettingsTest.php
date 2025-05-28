<?php

namespace Tests\Unit;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    /** @test */
    public function ffmpeg_video_codec_can_be_set_to_empty_string_and_retrieved()
    {
        // Ensure RefreshDatabase is used by TestCase to have a predictable DB state
        $groupName = 'general';
        $settingName = 'ffmpeg_codec_video';
        $settingValue = '';

        // Update or insert the specific setting property in the database
        DB::table('settings')->updateOrInsert(
            ['group' => $groupName, 'name' => $settingName],
            ['payload' => json_encode($settingValue), 'locked' => false] // spatie/laravel-settings will json_encode the value
        );
        
        // Forcing a clear of the resolved instance in the container, if any,
        // to ensure fresh load from DB.
        $this->app->forgetInstance(GeneralSettings::class);
        $settings = app(GeneralSettings::class);
        
        $this->assertSame($settingValue, $settings->ffmpeg_codec_video, "Failed asserting that the loaded ffmpeg_codec_video is ''.");
    }
}
