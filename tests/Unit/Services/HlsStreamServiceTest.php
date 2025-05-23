<?php

namespace Tests\Unit\Services;

use App\Services\HlsStreamService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Mockery; // Ensure Mockery is used if needed for proc_open, though Log facade is preferred.

class HlsStreamServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close(); // Clean up Mockery container if used.
        parent::tearDown();
    }

    protected function mockExternalDependencies()
    {
        // Mock Cache, Redis, File, Storage as they are always used
        Cache::shouldReceive('get')->andReturn(null); // No existing PID
        Cache::shouldReceive('forever')->andReturn(true);
        Cache::shouldReceive('forget')->andReturn(true);

        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('sadd')->andReturn(true);
        Redis::shouldReceive('srem')->andReturn(true);

        File::shouldReceive('ensureDirectoryExists')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true);
        Storage::shouldReceive('disk->path')->andReturn('/fake/storage/path');

        // Mock proc_open related global functions are tricky.
        // We will rely on Log facade to check the command.
        // If direct mocking of proc_open was needed, it's complex.
        // For now, we assume proc_open and related functions don't get called
        // due to how we structure the test or what we assert (e.g. command string via Log).
        // If the method runs to proc_open, we'd need a more robust solution for that.
        // The instructions allow focusing on command string, which Log::info helps with.
    }

    public function testStartStreamWithQsvCodec()
    {
        $this->mockExternalDependencies();

        // Mock GeneralSettings
        $settingsMock = Mockery::mock(GeneralSettings::class);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(3);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn('h264_qsv'); // QSV codec
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('aac');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('ffmpeg');
        $this->app->instance(GeneralSettings::class, $settingsMock);

        // Mock Config facade for proxy settings (can be overridden by GeneralSettings)
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('');

        // Capture the command string logged
        $loggedCommand = null;
        Log::shouldReceive('channel->info')
            ->once()
            ->withArgs(function ($message, $context) use (&$loggedCommand) {
                if (str_starts_with($message, 'Streaming channel')) {
                    // Extract command from "Streaming channel Test Channel with command: ACTUAL_COMMAND"
                    $parts = explode('with command: ', $message);
                    if (count($parts) > 1) {
                        $loggedCommand = $parts[1];
                    }
                    return true;
                }
                return false;
            });
        
        // Mock Log::channel('ffmpeg')->error() to prevent issues if proc_open fails during test
        // if the command execution part is not perfectly mocked/prevented.
        Log::shouldReceive('channel->error')->zeroOrMoreTimes();


        $service = new HlsStreamService();
        // We expect the method to attempt to run proc_open, which we cannot easily mock
        // without changing the source code or using advanced techniques.
        // The test will likely fail if proc_open is called with a real command unless
        // the environment happens to have ffmpeg and the command is valid.
        // For now, let's assume the Log::channel->info for the command is sufficient.
        // If proc_open is problematic, we'll need to adjust.
        // For now, the goal is to check $loggedCommand.
        // We need to handle the part after proc_open if it tries to execute.
        // Let's catch the exception if proc_open fails or related functions.

        try {
            $service->startStream('test_id', 'http://test.stream', 'Test Channel');
        } catch (\Throwable $e) {
            // Catch errors from proc_open, proc_get_status etc. if they are not mocked
            // and the command string was logged before the error.
            if (is_null($loggedCommand)) {
                // If command was not logged, the test premise failed earlier.
                throw $e;
            }
            // Otherwise, we assume the command was logged, and we can proceed to assert it.
            // This is a workaround for not mocking proc_open.
        }


        $this->assertNotNull($loggedCommand, "FFmpeg command was not logged.");
        $this->assertStringContainsString('-hwaccel qsv', $loggedCommand);
        $this->assertStringContainsString('-qsv_device /dev/dri/renderD128', $loggedCommand);
        $this->assertStringContainsString('-c:v h264_qsv', $loggedCommand);
    }

    public function testStartStreamWithNonQsvCodec()
    {
        $this->mockExternalDependencies();

        $settingsMock = Mockery::mock(GeneralSettings::class);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(3);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn('libx264'); // Non-QSV codec
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('aac');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('ffmpeg');
        $this->app->instance(GeneralSettings::class, $settingsMock);

        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('');
        
        $loggedCommand = null;
        Log::shouldReceive('channel->info')
            ->once()
            ->withArgs(function ($message, $context) use (&$loggedCommand) {
                if (str_starts_with($message, 'Streaming channel')) {
                    $parts = explode('with command: ', $message);
                    if (count($parts) > 1) {
                        $loggedCommand = $parts[1];
                    }
                    return true;
                }
                return false;
            });
        Log::shouldReceive('channel->error')->zeroOrMoreTimes();

        $service = new HlsStreamService();
        try {
            $service->startStream('test_id_non_qsv', 'http://test.stream.non.qsv', 'Test Channel Non QSV');
        } catch (\Throwable $e) {
            if (is_null($loggedCommand)) {
                throw $e;
            }
        }

        $this->assertNotNull($loggedCommand, "FFmpeg command was not logged for non-QSV test.");
        $this->assertStringNotContainsString('-hwaccel qsv', $loggedCommand);
        $this->assertStringNotContainsString('-qsv_device /dev/dri/renderD128', $loggedCommand);
        $this->assertStringContainsString('-c:v libx264', $loggedCommand);
    }
}
