<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\ChannelStreamController;
use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Symfony\Component\Process\Process as SymphonyProcess;
use Tests\TestCase;

class ChannelStreamControllerTest extends TestCase
{
    private const TEST_CHANNEL_ID = 123;
    // base64_encode(123) gives 'MTIz'. The controller adds '==' if not present.
    private const TEST_CHANNEL_ENCODED_ID = 'MTIz'; 

    protected $channelMock;
    protected $settingsMock;
    protected $requestMock;
    protected $processMock;
    protected $capturedCommand = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock global functions - this is tricky and might need adjustment or more specific tools
        // For now, we'll try to mock them to allow the code path to progress.
        // Mockery cannot mock global functions directly without helpers like php-mock.
        // We will rely on the fact that these are often not hit before command logging,
        // or structure tests to minimize their impact if direct mocking is too complex here.
        // The StreamedResponse itself will also not be fully executed.

        $this->channelMock = Mockery::mock(Channel::class);
        $this->channelMock->id = self::TEST_CHANNEL_ID;
        $this->channelMock->title_custom = 'Test Channel';
        $this->channelMock->url_custom = 'http://test.stream/url';
        $this->channelMock->playlist = null; // Default, can be overridden per test

        // Mock Channel::findOrFail
        // Note: Channel::findOrFail is static. A direct mock like this won't work without alias mocking.
        // A better way is to use $this->app->instance or similar for dependency injection if possible,
        // or use an alias mock for the model.
        // For simplicity in this context, we'll assume it can be handled or adjust if direct model mocking fails.
        // Let's plan to mock it via an alias if direct mocking doesn't intercept.
        // UPDATE: Laravel's model static methods are often handled via Eloquent's magic.
        // We might need to use an approach like:
        // Channel::shouldReceive('findOrFail')->...
        // Or, if the model is resolved via app container, mock its resolution.
        // For now, let's assume we can mock Channel::findOrFail directly with Mockery's alias feature.
        if (!Mockery::getContainer()->hasDefinition(Channel::class)) {
             Mockery::mock('alias:' . Channel::class);
        }
       

        $this->settingsMock = Mockery::mock(GeneralSettings::class);
        $this->app->instance(GeneralSettings::class, $this->settingsMock);

        $this->requestMock = Mockery::mock(Request::class);
        $this->requestMock->shouldReceive('ip')->andReturn('127.0.0.1');

        $this->processMock = Mockery::mock(SymphonyProcess::class);
        $this->processMock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
        // Mock run to call the callback immediately with no output or handle as needed
        $this->processMock->shouldReceive('run')
            ->andReturnUsing(function ($callback = null) {
                if ($callback) {
                    // Simulate some minimal process interaction if needed for code path
                    // $callback(SymphonyProcess::OUT, 'some output');
                }
                return 0; // Simulate successful exit
            })->passthru(); // passthru to allow multiple calls if the loop runs

        // Mock Process factory
        // Using 'overload:' for SymphonyProcess to intercept its static 'fromShellCommandline'
        // This might not work as expected for static methods.
        // A better way: Mock the specific instance creation if possible.
        // If fromShellCommandline is hard to mock statically, we might need to refactor controller
        // or use a different mocking strategy.
        // For now, let's try to use 'overload' and see.
        // UPDATE: Overload probably won't work for this.
        // We will mock the Log entry where the command is formed.
        // The Process object itself will be difficult to mock this way.
        // The key is to capture the command from the Log.

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf();
        Log::shouldReceive('info') // For command logging
            ->andReturnUsing(function ($message) {
                if (str_starts_with($message, 'Streaming channel')) {
                    $parts = explode('with command: ', $message);
                    if (count($parts) > 1) {
                        $this->capturedCommand = $parts[1];
                    }
                }
            });
        Log::shouldReceive('error'); // For any error logs

        Redis::shouldReceive('sadd')->andReturn(1);
        Redis::shouldReceive('srem')->andReturn(1);

        // Default Config mocks
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_additional_args', '')->andReturn('');
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_video')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_audio')->andReturn(null);
        Config::shouldReceive('get')->with('proxy.ffmpeg_codec_subtitles')->andReturn(null);
        
        // Mock ini_set, flush etc. - these are global and hard to mock without specific tools
        // We'll assume their absence won't break the path to command generation.
    }

    protected function commonSettingsMock($videoCodec)
    {
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(3);
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn($videoCodec);
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('aac'); // or 'copy'
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $this->settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('ffmpeg');
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testChannelStreamWithVaapiCodec()
    {
        Channel::shouldReceive('findOrFail->firstOrFail')->passthru(); // Eloquent chain
        Channel::shouldReceive('findOrFail')->once()->with(base64_decode(self::TEST_CHANNEL_ENCODED_ID))->andReturn($this->channelMock);
        $this->commonSettingsMock('h264_vaapi');

        // Expect the SymphonyProcess::fromShellCommandline to be called
        // This is the tricky part. We need to ensure the command is logged before this.
        // The Process mock setup in setUp might not be sufficient.
        // Let's refine the Log expectation to ensure it captures the command string.
        
        $controller = new ChannelStreamController();
        
        // The call to __invoke will return a StreamedResponse.
        // The callback within StreamedResponse executes the core logic.
        // We need to capture that callback and invoke it, or test differently.
        // For focusing on command string, we ensure Log mock captures it.
        // The actual streaming part (Process run) should be controlled.
        
        $response = $controller->__invoke($this->requestMock, self::TEST_CHANNEL_ENCODED_ID, 'mp4');
        
        // To execute the closure within StreamedResponse:
        ob_start();
        try {
            $response->sendContent();
        } catch (\Exception $e) {
            // Catch exceptions from process run or other parts if mocks are not perfect
            if (is_null($this->capturedCommand)) throw $e; // rethrow if command not captured
        }
        ob_end_clean();


        $this->assertNotNull($this->capturedCommand, "FFmpeg command was not logged for VAAPI test.");
        $this->assertStringContainsString('-hwaccel vaapi', $this->capturedCommand);
        $this->assertStringContainsString('-vaapi_device /dev/dri/renderD128', $this->capturedCommand);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $this->capturedCommand);
        $this->assertStringContainsString('-c:v h264_vaapi', $this->capturedCommand);
        $this->assertStringNotContainsString('-hwaccel qsv', $this->capturedCommand);
    }

    public function testChannelStreamWithQsvCodec()
    {
        Channel::shouldReceive('findOrFail->firstOrFail')->passthru();
        Channel::shouldReceive('findOrFail')->once()->with(base64_decode(self::TEST_CHANNEL_ENCODED_ID))->andReturn($this->channelMock);
        $this->commonSettingsMock('h264_qsv');

        $controller = new ChannelStreamController();
        $response = $controller->__invoke($this->requestMock, self::TEST_CHANNEL_ENCODED_ID, 'mp4');
        ob_start();
        try {
            $response->sendContent();
        } catch (\Exception $e) {
            if (is_null($this->capturedCommand)) throw $e;
        }
        ob_end_clean();

        $this->assertNotNull($this->capturedCommand, "FFmpeg command was not logged for QSV test.");
        $this->assertStringContainsString('-hwaccel qsv', $this->capturedCommand);
        $this->assertStringContainsString('-qsv_device /dev/dri/renderD128', $this->capturedCommand);
        $this->assertStringContainsString('-c:v h264_qsv', $this->capturedCommand);
        $this->assertStringNotContainsString('-hwaccel vaapi', $this->capturedCommand);
        $this->assertStringNotContainsString('-hwaccel_output_format vaapi', $this->capturedCommand);
    }

    public function testChannelStreamWithSoftwareCodec()
    {
        Channel::shouldReceive('findOrFail->firstOrFail')->passthru();
        Channel::shouldReceive('findOrFail')->once()->with(base64_decode(self::TEST_CHANNEL_ENCODED_ID))->andReturn($this->channelMock);
        $this->commonSettingsMock('libx264');

        $controller = new ChannelStreamController();
        $response = $controller->__invoke($this->requestMock, self::TEST_CHANNEL_ENCODED_ID, 'mp4');
        ob_start();
        try {
            $response->sendContent();
        } catch (\Exception $e) {
            if (is_null($this->capturedCommand)) throw $e;
        }
        ob_end_clean();

        $this->assertNotNull($this->capturedCommand, "FFmpeg command was not logged for Software test.");
        $this->assertStringNotContainsString('-hwaccel vaapi', $this->capturedCommand);
        $this->assertStringNotContainsString('-vaapi_device /dev/dri/renderD128', $this->capturedCommand);
        $this->assertStringNotContainsString('-hwaccel_output_format vaapi', $this->capturedCommand);
        $this->assertStringNotContainsString('-hwaccel qsv', $this->capturedCommand);
        $this->assertStringNotContainsString('-qsv_device /dev/dri/renderD128', $this->capturedCommand);
        $this->assertStringContainsString('-c:v libx264', $this->capturedCommand);
    }
}
