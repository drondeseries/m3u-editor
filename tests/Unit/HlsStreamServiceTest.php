<?php

namespace Tests\Unit;

use App\Services\HlsStreamService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class HlsStreamServiceTest extends TestCase
{
    protected MockInterface $generalSettingsMock;
    protected HlsStreamService $hlsStreamService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generalSettingsMock = $this->mock(GeneralSettings::class);
        $this->app->instance(GeneralSettings::class, $this->generalSettingsMock);

        Cache::shouldReceive('get')->with(\Mockery::pattern('/^hls:pid:.*/'))->andReturnNull();
        Cache::shouldReceive('forever')->with(\Mockery::pattern('/^hls:pid:.*/'), \Mockery::any())->andReturnTrue();
        Cache::shouldReceive('get')->with('ffmpeg_encoders')->andReturnNull(); // For FfmpegCodecService if used indirectly

        Storage::shouldReceive('disk->path')->andReturn('/fake/storage/path');
        File::shouldReceive('ensureDirectoryExists')->andReturnTrue();
        File::shouldReceive('deleteDirectory')->andReturnTrue();
        
        // Mock crucial global functions/methods if they interfere with command string testing
        // For example, if proc_open is called, we need to prevent its actual execution.
        // This can be complex. The current tests rely on Log output.

        $this->hlsStreamService = new HlsStreamService();
    }

    public function test_ffmpeg_command_without_vaapi() // Renamed
    {
        // Arrange: VA-API disabled
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_vaapi_enabled')->andReturn(false); // Changed from qsv to vaapi
        // QSV lines removed
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_video')->andReturn('libx264');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_audio')->andReturn('aac');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_path')->andReturn('ffmpeg');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_user_agent')->andReturn('TestAgent');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_debug')->andReturn(false);
        // fill other general settings if they are accessed
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_max_tries')->andReturn(3);


        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('');
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null); // Not set by env
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles')->andReturn(null);


        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('ffmpeg', $message);
            $this->assertStringNotContainsString('h264_vaapi', $message); // Check VA-API not present
            $this->assertStringNotContainsString('-hwaccel vaapi', $message); // Check VA-API not present
            $this->assertStringNotContainsString('-init_hw_device vaapi', $message); // Check VA-API not present
            $this->assertStringContainsString('-c:v libx264', $message); // Check software codec is present
            return true;
        });
        Log::shouldReceive('error'); // Allow error logs from proc_open failure

        try {
            $this->hlsStreamService->startStream('channel', '1', 'http://test.stream', 'Test Channel');
        } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
            // Catching specific exception if proc_open is actually called and fails due to mock setup
            $this->assertStringContainsString('Failed to launch FFmpeg', $e->getMessage());
        } catch (\Exception $e) {
            // General exception for other issues, like "Could not start stream" if our mocks are incomplete for that path
             if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    public function test_ffmpeg_command_with_vaapi_enabled() // Renamed
    {
        // Arrange: VA-API enabled
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_vaapi_enabled')->andReturn(true); // Changed from qsv to vaapi
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_vaapi_device')->andReturn('/dev/dri/renderD128'); // Added for VA-API
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_vaapi_video_filter')->andReturn('scale_vaapi=format=nv12'); // Added for VA-API
        // QSV lines removed
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_video')->andReturn('libx264'); // Original base codec
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_audio')->andReturn('aac');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_path')->andReturn('ffmpeg');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_user_agent')->andReturn('TestAgent');
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_debug')->andReturn(false);
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_max_tries')->andReturn(3);


        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('');
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles')->andReturn(null);

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('ffmpeg', $message);
            $this->assertStringContainsString('-init_hw_device vaapi=va_device:/dev/dri/renderD128', $message); // Check VA-API
            $this->assertStringContainsString('-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi', $message); // Check VA-API
            $this->assertStringContainsString("-vf 'scale_vaapi=format=nv12'", $message); // Check VA-API
            $this->assertStringContainsString('-c:v h264_vaapi', $message); // Check VA-API
            $this->assertStringNotContainsString('-c:v libx264', $message); // Check software codec NOT present
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', '1', 'http://test.stream', 'Test Channel VAAPI'); // Updated title for clarity
        } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) {
            $this->assertStringContainsString('Failed to launch FFmpeg', $e->getMessage());
        } catch (\Exception $e) {
             if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }
}
