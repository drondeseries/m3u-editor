<?php

namespace Tests\Unit;

use App\Services\DirectStreamManager;
use Tests\TestCase; // Using Laravel's base TestCase for any potential Laravel features

class DirectStreamManagerTest extends TestCase
{
    /** @test */
    public function direct_determine_video_codec_uses_setting_if_valid()
    {
        $this->assertEquals('libx264', DirectStreamManager::determineVideoCodec('libx264'));
        $this->assertEquals('h264_vaapi', DirectStreamManager::determineVideoCodec('h264_vaapi'));
    }

    /** @test */
    public function direct_determine_video_codec_uses_copy_if_setting_is_empty_string()
    {
        $this->assertEquals('copy', DirectStreamManager::determineVideoCodec(''));
    }

    /** @test */
    public function direct_determine_video_codec_uses_copy_if_setting_is_null()
    {
        $this->assertEquals('copy', DirectStreamManager::determineVideoCodec(null));
    }

    /** @test */
    public function direct_determine_video_codec_uses_copy_as_default_if_argument_omitted_though_not_typical_use()
    {
        // This tests the ?? 'copy' part of the implementation if null is passed explicitly
        // which is what ($settings['ffmpeg_codec_video'] ?? null) does.
        $this->assertEquals('copy', DirectStreamManager::determineVideoCodec(null));
    }
}
