<?php

namespace Tests\Unit;

use App\Services\HlsStreamService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HlsStreamServiceTest extends TestCase
{
    protected GeneralSettings $generalSettings;
    protected HlsStreamService $hlsStreamService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generalSettings = new GeneralSettings();
        
        // Default settings for most full stream tests (not for direct static method tests)
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->generalSettings->ffmpeg_qsv_enabled = false;
        $this->generalSettings->ffmpeg_codec_video = 'libx264'; // Default for full stream tests
        $this->generalSettings->ffmpeg_codec_audio = 'aac';
        $this->generalSettings->ffmpeg_codec_subtitles = 'copy';
        $this->generalSettings->ffmpeg_path = 'ffmpeg';
        $this->generalSettings->ffmpeg_user_agent = 'TestAgent';
        $this->generalSettings->ffmpeg_debug = false;
        $this->generalSettings->ffmpeg_max_tries = 3;
        $this->generalSettings->ffmpeg_vaapi_device = '/dev/dri/renderD128';
        $this->generalSettings->ffmpeg_vaapi_video_filter = 'scale_vaapi=format=nv12';
        $this->generalSettings->ffmpeg_qsv_device = '/dev/dri/renderD128';
        $this->generalSettings->ffmpeg_qsv_video_filter = null;
        $this->generalSettings->ffmpeg_qsv_encoder_options = null;
        $this->generalSettings->ffmpeg_qsv_additional_args = null;
        // Add other GeneralSettings properties with defaults if they are accessed by HlsStreamService
        $this->generalSettings->navigation_position = 'left';
        $this->generalSettings->show_breadcrumbs = true;
        $this->generalSettings->show_logs = false;
        $this->generalSettings->show_api_docs = false;
        $this->generalSettings->show_queue_manager = false;
        $this->generalSettings->content_width = 'xl';
        $this->generalSettings->mediaflow_proxy_url = null;
        $this->generalSettings->mediaflow_proxy_port = null;
        $this->generalSettings->mediaflow_proxy_password = null;
        $this->generalSettings->mediaflow_proxy_user_agent = null;
        $this->generalSettings->mediaflow_proxy_playlist_user_agent = false;
        $this->generalSettings->hardware_acceleration_method = 'none';


        $this->app->instance(GeneralSettings::class, $this->generalSettings);

        // Mock external dependencies for full stream tests
        Cache::shouldReceive('get')->with(\Mockery::pattern('/^hls:pid:.*/'))->andReturnNull()->byDefault();
        Cache::shouldReceive('forever')->with(\Mockery::pattern('/^hls:pid:.*/'), \Mockery::any())->andReturnTrue()->byDefault();
        Cache::shouldReceive('get')->with('ffmpeg_encoders')->andReturnNull()->byDefault(); // For FfmpegCodecService if used indirectly

        Storage::shouldReceive('disk->path')->andReturn('/fake/storage/path')->byDefault();
        File::shouldReceive('ensureDirectoryExists')->andReturnTrue()->byDefault();
        File::shouldReceive('deleteDirectory')->andReturnTrue()->byDefault();
        
        $this->hlsStreamService = new HlsStreamService();
    }

    private function setupDefaultConfigMocks()
    {
        // These are for the full startStream method tests, not the static determineVideoCodec tests
        Config::shouldReceive('get')->with('proxy.ffmpeg_path', null)->andReturn(null)->byDefault();
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video', null)->andReturn(null)->byDefault();
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio', null)->andReturn(null)->byDefault();
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles', null)->andReturn(null)->byDefault();
        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('')->byDefault();
    }

    // --- Tests for HlsStreamService::determineVideoCodec ---

    /** @test */
    public function hls_determine_video_codec_uses_config_if_set_and_not_empty()
    {
        $this->assertEquals('config_codec', HlsStreamService::determineVideoCodec('config_codec', 'settings_codec'));
        $this->assertEquals('config_codec', HlsStreamService::determineVideoCodec('config_codec', null));
        $this->assertEquals('config_codec', HlsStreamService::determineVideoCodec('config_codec', ''));
    }

    /** @test */
    public function hls_determine_video_codec_uses_settings_if_config_is_null_or_empty()
    {
        $this->assertEquals('settings_codec', HlsStreamService::determineVideoCodec(null, 'settings_codec'));
        $this->assertEquals('settings_codec', HlsStreamService::determineVideoCodec('', 'settings_codec'));
        // If settings is also empty or null, it should default to copy (tested next)
    }
    
    /** @test */
    public function hls_determine_video_codec_uses_settings_if_config_is_null_and_settings_not_empty()
    {
        $this->assertEquals('settings_codec', HlsStreamService::determineVideoCodec(null, 'settings_codec'));
    }

    /** @test */
    public function hls_determine_video_codec_uses_settings_if_config_is_empty_and_settings_not_empty()
    {
        $this->assertEquals('settings_codec', HlsStreamService::determineVideoCodec('', 'settings_codec'));
    }
    
    /** @test */
    public function hls_determine_video_codec_defaults_to_copy_if_both_config_and_settings_are_null()
    {
        $this->assertEquals('copy', HlsStreamService::determineVideoCodec(null, null));
    }

    /** @test */
    public function hls_determine_video_codec_defaults_to_copy_if_both_config_and_settings_are_empty()
    {
        $this->assertEquals('copy', HlsStreamService::determineVideoCodec('', ''));
    }

    /** @test */
    public function hls_determine_video_codec_defaults_to_copy_if_config_is_null_and_settings_is_empty()
    {
        $this->assertEquals('copy', HlsStreamService::determineVideoCodec(null, ''));
    }

    /** @test */
    public function hls_determine_video_codec_defaults_to_copy_if_config_is_empty_and_settings_is_null()
    {
        $this->assertEquals('copy', HlsStreamService::determineVideoCodec('', null));
    }

    // --- Existing full startStream method tests (can be kept or refactored if needed) ---
    // Note: These tests will now use the refactored determineVideoCodec internally.
    // The previous Config mocks related to 'proxy.ffmpeg_codec_video' might need adjustment
    // if we want to test specific scenarios of the static method through these full tests.
    // For now, ensuring they still pass with the default config mock for video codec (null) is fine.

    public function test_ffmpeg_command_without_vaapi()
    {
        $this->setupDefaultConfigMocks(); // Ensures 'proxy.ffmpeg_codec_video' is null

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            // generalSettings->ffmpeg_codec_video is 'libx264' by default in setUp
            // determineVideoCodec(null, 'libx264') -> 'libx264'
            $this->assertStringContainsString('-c:v libx264', $message);
            $this->assertStringNotContainsString('h264_vaapi', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', '1', 'http://test.stream', 'Test Channel');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }
    
    /** @test */
    public function test_start_stream_uses_copy_codec_when_settings_and_config_video_codec_are_empty()
    {
        $this->generalSettings->ffmpeg_codec_video = ''; // Settings provide empty string
        $this->setupDefaultConfigMocks(); // Config for video is null
        // Expected: determineVideoCodec(null, '') -> 'copy'

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('-c:v copy', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'test_copy_codec', 'http://test.stream', 'Test Copy Codec');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }


    public function test_ffmpeg_command_with_vaapi_enabled()
    {
        $this->generalSettings->ffmpeg_vaapi_enabled = true;
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('ffmpeg', $message);
            $this->assertStringContainsString("-init_hw_device vaapi=va_device:" . $this->generalSettings->ffmpeg_vaapi_device, $message);
            $this->assertStringContainsString('-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi', $message);
            $this->assertStringContainsString("-vf '" . $this->generalSettings->ffmpeg_vaapi_video_filter . "'", $message);
            $this->assertStringContainsString('-c:v h264_vaapi', $message); // VAAPI overrides determineVideoCodec
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', '1', 'http://test.stream', 'Test Channel VAAPI');
        } catch (\Exception $e) {
             if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    // ... (keeping other full startStream tests as they were, they test other aspects)


    public function test_ffmpeg_command_with_qsv_enabled_defaults()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('ffmpeg', $message);
            $this->assertStringContainsString("-init_hw_device qsv=qsv_hw:'" . $this->generalSettings->ffmpeg_qsv_device . "'", $message);
            $this->assertStringContainsString('-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv', $message);
            $this->assertStringContainsString('-c:v h264_qsv', $message); // QSV overrides determineVideoCodec
            $this->assertStringNotContainsString('-vf', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_default', 'http://test.stream', 'Test QSV Defaults');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    public function test_ffmpeg_command_with_qsv_enabled_custom_device()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->generalSettings->ffmpeg_qsv_device = '/dev/dri/renderD129';
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString("-init_hw_device qsv=qsv_hw:'/dev/dri/renderD129'", $message);
            $this->assertStringContainsString('-c:v h264_qsv', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_custom_device', 'http://test.stream', 'Test QSV Custom Device');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    public function test_ffmpeg_command_with_qsv_enabled_with_filter()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->generalSettings->ffmpeg_qsv_video_filter = 'vpp_qsv=w=1280:h=720:format=nv12';
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString("-vf 'vpp_qsv=w=1280:h=720:format=nv12'", $message);
            $this->assertStringContainsString('-c:v h264_qsv', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_filter', 'http://test.stream', 'Test QSV Filter');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    public function test_ffmpeg_command_with_qsv_enabled_with_encoder_options()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->generalSettings->ffmpeg_qsv_encoder_options = '-profile:v high -g 90';
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('-c:v h264_qsv -profile:v high -g 90 ', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_encoder_opts', 'http://test.stream', 'Test QSV Encoder Options');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }

    public function test_ffmpeg_command_with_qsv_enabled_with_additional_args()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_vaapi_enabled = false;
        $this->generalSettings->ffmpeg_qsv_additional_args = '-some_qsv_specific_arg value';
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString('-some_qsv_specific_arg value', $message);
            $this->assertStringContainsString('-c:v h264_qsv', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_add_args', 'http://test.stream', 'Test QSV Additional Args');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'proc_open') === false && strpos($e->getMessage(), 'proc_get_status') === false && strpos($e->getMessage(), 'Unable to launch process') === false) {
                $this->fail('Unexpected exception type or message: ' . $e->getMessage());
            }
        }
    }
    
    public function test_ffmpeg_command_with_qsv_enabled_and_vaapi_enabled_qsv_takes_precedence()
    {
        $this->generalSettings->ffmpeg_qsv_enabled = true;
        $this->generalSettings->ffmpeg_qsv_device = '/dev/dri/qsv_device';
        $this->generalSettings->ffmpeg_vaapi_enabled = true; 
        $this->generalSettings->ffmpeg_vaapi_device = '/dev/dri/vaapi_device';
        $this->setupDefaultConfigMocks();

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message) {
            $this->assertStringContainsString("-init_hw_device qsv=qsv_hw:'/dev/dri/qsv_device'", $message);
            $this->assertStringContainsString('-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv', $message);
            $this->assertStringContainsString('-c:v h264_qsv', $message);
            $this->assertStringNotContainsString('vaapi_device', $message);
            $this->assertStringNotContainsString('-hwaccel vaapi', $message);
            $this->assertStringNotContainsString('h264_vaapi', $message);
            return true;
        });
        Log::shouldReceive('error');

        try {
            $this->hlsStreamService->startStream('channel', 'qsv_over_vaapi', 'http://test.stream', 'Test QSV Takes Precedence');
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
