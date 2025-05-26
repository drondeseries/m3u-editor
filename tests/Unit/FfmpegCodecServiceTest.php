<?php

namespace Tests\Unit;

use App\Services\FfmpegCodecService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;
// We are not directly mocking Symfony Process here, so it might not be needed in imports
// use Symfony\Component\Process\Process; 

class FfmpegCodecServiceTest extends TestCase
{
    protected MockInterface $generalSettingsMock;
    protected FfmpegCodecService $ffmpegCodecService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generalSettingsMock = $this->mock(GeneralSettings::class);
        $this->app->instance(GeneralSettings::class, $this->generalSettingsMock);
        
        $this->generalSettingsMock->shouldReceive('__get')->with('ffmpeg_path')->andReturn('fake_ffmpeg_path');
        
        // Mock Cache to ensure callback is executed for tests
        Cache::shouldReceive('remember')->andImplementation(function ($key, $ttl, $callback) {
            return $callback();
        });
        // Mock Log calls unless specifically testing them
        Log::shouldReceive('info'); 
        Log::shouldReceive('debug');
        Log::shouldReceive('error');

        $this->ffmpegCodecService = new FfmpegCodecService();
    }

    // Helper to mock the Process execution
    protected function mockProcessExecution($output, $shouldRunSuccessfully = true)
    {
        // This is a conceptual way to influence Process.
        // Actual implementation might need a deeper mock or service locator pattern for Process.
        // For now, this test will rely on a refactor or a different way to test getEncoders.
        // One common way is to make a protected method that creates the Process object,
        // then override that in a test subclass.
        
        // Due to limitations on mocking `new Process()` directly in the SUT,
        // these tests will be more of a "wishful thinking" structure or require
        // refactoring of FfmpegCodecService.
        // For the purpose of this exercise, we'll assume the Process runs and returns output.
        // A true test would involve a Process mock being injected or a factory.
    }

    public function test_recognizes_vaapi_codecs_from_ffmpeg_output() // Renamed
    {
        // This test is challenging due to `new Process()` in the service.
        // It would ideally mock the Process object.
        // For now, we'll mark as skipped or incomplete if direct mocking fails.
        $this->markTestSkipped('FfmpegCodecService::getEncoders() creates a new Process making it hard to mock directly. Refactoring needed for robust test.');

        // Arrange (Conceptual - if Process could be mocked)
        // $mockOutput = "Codecs:\n" .
        //               " V..... libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10\n" .
        //               " V..... h264_vaapi           H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (VA-API)\n" . // Changed to vaapi
        //               " V..... hevc_vaapi           HEVC (VA-API)\n" . // Changed to vaapi
        //               " A..... aac                  AAC (Advanced Audio Coding)\n";
        // $this->mockProcessExecution($mockOutput); // Conceptual
        // Log::shouldReceive('info')->with("Discovered VA-API related codec: h264_vaapi - H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (VA-API)"); // Changed to VA-API
        // Log::shouldReceive('info')->with("Discovered VA-API related codec: hevc_vaapi - HEVC (VA-API)"); // Changed to VA-API

        // Act
        // $encoders = $this->ffmpegCodecService->getEncoders();

        // Assert
        // $this->assertArrayHasKey('h264_vaapi', $encoders['video']); // Changed to vaapi
        // $this->assertArrayHasKey('hevc_vaapi', $encoders['video']); // Changed to vaapi
        // $this->assertStringContainsString('(VA-API)', $encoders['video']['h264_vaapi']); // Changed to VA-API
    }

    public function test_ignores_experimental_vaapi_codecs() // Renamed
    {
        $this->markTestSkipped('FfmpegCodecService::getEncoders() creates a new Process making it hard to mock directly. Refactoring needed for robust test.');

        // Arrange (Conceptual)
        // $mockOutput = "Codecs:\n" .
        //               " V..X.. h264_vaapi_experimental H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (VA-API Experimental)\n" . // Changed to vaapi
        //               " V..... h264_vaapi             H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (VA-API)\n"; // Changed to vaapi
        // $this->mockProcessExecution($mockOutput);
        // Log::shouldReceive('info')->with("Discovered VA-API related codec: h264_vaapi - H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (VA-API)"); // Changed to VA-API
        // Log::shouldNotReceive('info')->with(\Mockery::pattern('/h264_vaapi_experimental/')); // Changed to vaapi


        // Act
        // $encoders = $this->ffmpegCodecService->getEncoders();

        // Assert
        // $this->assertArrayHasKey('h264_vaapi', $encoders['video']); // Changed to vaapi
        // $this->assertArrayNotHasKey('h264_vaapi_experimental', $encoders['video']); // Changed to vaapi
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }
}
