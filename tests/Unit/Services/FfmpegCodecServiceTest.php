<?php

namespace Tests\Unit\Services;

use App\Services\FfmpegCodecService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Mockery;

class FfmpegCodecServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetEncodersParsesQsvAndOtherCodecsCorrectly()
    {
        // Mock GeneralSettings to provide a ffmpeg_path
        $settingsMock = Mockery::mock(GeneralSettings::class);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('dummy_ffmpeg');
        $this->app->instance(GeneralSettings::class, $settingsMock);

        // Mock Config to ensure our dummy_ffmpeg_path is used
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);

        // Mock FFmpeg output
        $ffmpegOutput = <<<EOT
Codecs:
 D..... = Decoding supported
 .E.... = Encoding supported
 ..V... = Video codec
 ..A... = Audio codec
 ..S... = Subtitle codec
 ...I.. = Intra frame-only codec
 ....L. = Lossy compression
 .....S = Lossless compression
 -------
 Encoders:
 V..... h264_qsv             H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (Intel Quick Sync Video acceleration)
 V..... hevc_qsv             HEVC (Intel Quick Sync Video acceleration)
 V..X.. experimental_hevc_qsv HEVC Experimental (Intel Quick Sync Video acceleration)
 V..... libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10
 V..... mjpeg                MJPEG (Motion JPEG)
 A..... aac                  AAC (Advanced Audio Coding)
 A..X.. experimental_aac     AAC Experimental
 S..... srt                  SubRip subtitle
EOT;

        // Mock Process class
        $processMock = Mockery::mock('overload:' . Process::class);
        $processMock->shouldReceive('__construct')
            ->with(['dummy_ffmpeg', '-hide_banner', '-encoders'])
            ->once();
        $processMock->shouldReceive('mustRun')->once();
        $processMock->shouldReceive('getOutput')->once()->andReturn($ffmpegOutput);

        // Mock Log facade
        Log::shouldReceive('error')->never(); // Should not have errors parsing this
        Log::shouldReceive('info')->once(); // For "FFmpeg encoders command executed successfully."
        Log::shouldReceive('debug')->once(); // For the output itself

        // Mock Cache::remember to execute the callback
        Cache::shouldReceive('remember')
            ->with('ffmpeg_encoders', 3600, Mockery::on(function ($closure) {
                return is_callable($closure);
            }))
            ->once()
            ->andReturnUsing(function ($key, $ttl, $closure) use ($settingsMock) {
                // We need to ensure the closure uses the mocked GeneralSettings if it tries to resolve it again
                // However, the path is passed as an argument to the closure, so it should be fine.
                $ffmpegPath = config('proxy.ffmpeg_path') ?: $settingsMock->ffmpeg_path;
                return $closure($ffmpegPath);
            });


        $service = new FfmpegCodecService();
        $encoders = $service->getEncoders();

        // Assertions for video codecs
        $this->assertArrayHasKey('video', $encoders);
        $this->assertArrayHasKey('h264_qsv', $encoders['video']);
        $this->assertEquals('<strong>h264_qsv</strong></br><small><em>H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (Intel Quick Sync Video acceleration)</em></small>', $encoders['video']['h264_qsv']);
        $this->assertArrayHasKey('hevc_qsv', $encoders['video']);
        $this->assertEquals('<strong>hevc_qsv</strong></br><small><em>HEVC (Intel Quick Sync Video acceleration)</em></small>', $encoders['video']['hevc_qsv']);
        $this->assertArrayHasKey('libx264', $encoders['video']);
        $this->assertEquals('<strong>libx264</strong></br><small><em>libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10</em></small>', $encoders['video']['libx264']);
        
        // Assert that experimental video codec is filtered out
        $this->assertArrayNotHasKey('experimental_hevc_qsv', $encoders['video']);

        // Assertions for audio codecs
        $this->assertArrayHasKey('audio', $encoders);
        $this->assertArrayHasKey('aac', $encoders['audio']);
        $this->assertEquals('<strong>aac</strong></br><small><em>AAC (Advanced Audio Coding)</em></small>', $encoders['audio']['aac']);
        
        // Assert that experimental audio codec is filtered out
        $this->assertArrayNotHasKey('experimental_aac', $encoders['audio']);

        // Assertions for subtitle codecs
        $this->assertArrayHasKey('subtitle', $encoders);
        $this->assertArrayHasKey('srt', $encoders['subtitle']);
        $this->assertEquals('<strong>srt</strong></br><small><em>SubRip subtitle</em></small>', $encoders['subtitle']['srt']);
    }

    public function testGetEncodersHandlesProcessFailedException()
    {
        $settingsMock = Mockery::mock(GeneralSettings::class);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('dummy_ffmpeg_fail');
        $this->app->instance(GeneralSettings::class, $settingsMock);
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);

        $processMock = Mockery::mock('overload:' . Process::class);
        $processMock->shouldReceive('__construct')->once();
        $processMock->shouldReceive('mustRun')->once()->andThrow(new \Symfony\Component\Process\Exception\ProcessFailedException(Mockery::mock(Process::class)));
        
        Log::shouldReceive('error')->once()->with(Mockery::pattern('/FFmpeg encoders command failed:/'));
        Log::shouldReceive('info')->never();
        Log::shouldReceive('debug')->never();

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $closure) use ($settingsMock) {
                 $ffmpegPath = config('proxy.ffmpeg_path') ?: $settingsMock->ffmpeg_path;
                return $closure($ffmpegPath);
            });

        $service = new FfmpegCodecService();
        $encoders = $service->getEncoders();

        $this->assertEquals(['video' => [], 'audio' => [], 'subtitle' => []], $encoders);
    }

    public function testGetEncodersHandlesEmptyOutput()
    {
        $settingsMock = Mockery::mock(GeneralSettings::class);
        $settingsMock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('dummy_ffmpeg_empty');
        $this->app->instance(GeneralSettings::class, $settingsMock);
        Config::shouldReceive('get')->with('proxy.ffmpeg_path')->andReturn(null);

        $processMock = Mockery::mock('overload:' . Process::class);
        $processMock->shouldReceive('__construct')->once();
        $processMock->shouldReceive('mustRun')->once();
        $processMock->shouldReceive('getOutput')->once()->andReturn(''); // Empty output

        Log::shouldReceive('error')->once()->with('FFmpeg encoders command returned no output.');
        Log::shouldReceive('info')->never(); // No success if output is empty
        Log::shouldReceive('debug')->never();


        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $closure) use ($settingsMock) {
                 $ffmpegPath = config('proxy.ffmpeg_path') ?: $settingsMock->ffmpeg_path;
                return $closure($ffmpegPath);
            });

        $service = new FfmpegCodecService();
        $encoders = $service->getEncoders();
        $this->assertEquals(['video' => [], 'audio' => [], 'subtitle' => []], $encoders);
    }
}
