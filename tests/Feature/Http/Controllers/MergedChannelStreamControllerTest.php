<?php

use App\Models\Channel;
use App\Models\MergedChannel;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Process\Process as SymphonyProcess;

uses(RefreshDatabase::class);

// Helper to create a MergedChannel with source channels
function createMergedChannelWithSources(User $user, array $sourcesData): MergedChannel
{
    $mergedChannel = MergedChannel::factory()->for($user)->create();
    foreach ($sourcesData as $source) {
        $channel = Channel::factory()->for($user)->create(['url' => $source['url']]);
        $mergedChannel->sourceChannels()->attach($channel->id, ['priority' => $source['priority']]);
    }
    return $mergedChannel->load('sourceChannels'); // Eager load for easier access in tests
}

test('TestCase 1: Successful Stream (Primary Source)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock GeneralSettings
    $settings = $this->mock(GeneralSettings::class, function (MockInterface $mock) {
        $mock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('/usr/bin/ffmpeg');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(1); // Only 1 try for simplicity
        $mock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_reconnect_delay_max')->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_stream_idle_timeout')->andReturn(10);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
    });
    // Mock config as well, since controller uses both GeneralSettings and config()
    Config::set('proxy.ffmpeg_path', '/usr/bin/ffmpeg');
    Config::set('proxy.ffmpeg_codec_video', 'copy');
    Config::set('proxy.ffmpeg_codec_audio', 'copy');
    Config::set('proxy.ffmpeg_codec_subtitles', 'copy');
    Config::set('proxy.ffmpeg_additional_args', '');


    $primaryUrl = 'http://primarysource.test/stream.m3u8';
    $secondaryUrl = 'http://secondarysource.test/stream.m3u8';

    $mergedChannel = createMergedChannelWithSources($user, [
        ['url' => $primaryUrl, 'priority' => 0], // Highest priority
        ['url' => $secondaryUrl, 'priority' => 1],
    ]);

    $mockedProcess = $this->mock(SymphonyProcess::class, function (MockInterface $mock) use ($primaryUrl) {
        // Expect the command for the primary URL
        $mock->shouldReceive('fromShellCommandline')
            ->once()
            ->with(Mockery::on(function ($command) use ($primaryUrl) {
                return str_contains($command, $primaryUrl);
            }))
            ->andReturnSelf(); // Return the mock itself for chained calls

        $mock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
        $mock->shouldReceive('setIdleTimeout')->with(10)->andReturnSelf();
        $mock->shouldReceive('start')->once();
        $mock->shouldReceive('getPid')->andReturn(12345);
        
        // Simulate successful streaming
        $mock->shouldReceive('getIterator')
            ->andReturn(new ArrayIterator(['stream_data_chunk_1', 'stream_data_chunk_2']));
        $mock->shouldReceive('wait')->once();
        $mock->shouldReceive('isSuccessful')->once()->andReturn(true);
        $mock->shouldReceive('isRunning')->andReturn(false); // Initially not running, then after start, then false after stop/wait
    });
    
    // Replace the Process facade with our mock, or ensure the controller uses app(SymphonyProcess::class)
    // For this example, we assume the controller resolves SymphonyProcess via the container
    $this->app->instance(SymphonyProcess::class, $mockedProcess);


    Log::shouldReceive('channel->info')->with(Mockery::on(function($message) use ($primaryUrl) {
        return str_contains($message, "Attempting to stream MergedChannel ID: {$mergedChannel->id}") && str_contains($message, $primaryUrl);
    }))->once();
     Log::shouldReceive('channel->info')->with(Mockery::on(function($message) use ($primaryUrl) {
        return str_contains($message, "FFmpeg command for MergedChannel {$mergedChannel->id}") && str_contains($message, $primaryUrl);
    }))->once();
    Log::shouldReceive('channel->info')->with(Mockery::on(function($message) use ($primaryUrl) {
        return str_contains($message, "Stream completed successfully for MergedChannel ID: {$mergedChannel->id}") && str_contains($message, $primaryUrl);
    }))->once();
    Log::shouldReceive('channel->info')->zeroOrMoreTimes(); // Allow other info logs
    Log::shouldReceive('channel->warning')->zeroOrMoreTimes();
    Log::shouldReceive('channel->error')->zeroOrMoreTimes();


    $response = $this->get(route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'video/MP2T');
    $response->assertHeader('X-Accel-Buffering', 'no'); // Check for Nginx buffering disable header
    $response->assertSee('stream_data_chunk_1stream_data_chunk_2');

    // Verify that Redis entries were made (simplified check)
    // This requires setting up Redis for testing or mocking Redis facade
    // For now, we'll skip direct Redis assertions to keep it simpler,
    // but in a full setup, you'd mock Redis::* calls or use a test Redis instance.

    // Ensure the mock expectations were met (handled by Mockery automatically on teardown)
});

test('TestCase 2: Failover to Secondary Source', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock GeneralSettings & Config
    $this->mock(GeneralSettings::class, function (MockInterface $mock) {
        $mock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('/usr/bin/ffmpeg');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_reconnect_delay_max')->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_stream_idle_timeout')->andReturn(10);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
    });
    Config::set('proxy.ffmpeg_path', '/usr/bin/ffmpeg');
    Config::set('proxy.ffmpeg_codec_video', 'copy');
    Config::set('proxy.ffmpeg_codec_audio', 'copy');
    Config::set('proxy.ffmpeg_codec_subtitles', 'copy');
    Config::set('proxy.ffmpeg_additional_args', '');

    $primaryUrl = 'http://primaryfail.test/stream.m3u8';
    $secondaryUrl = 'http://secondarysuccess.test/stream.m3u8';

    $mergedChannel = createMergedChannelWithSources($user, [
        ['url' => $primaryUrl, 'priority' => 0],
        ['url' => $secondaryUrl, 'priority' => 1],
    ]);

    // Mock SymphonyProcess
    // This time, we need to mock it to handle two different command calls.
    // We can use a closure with shouldReceive to inspect the command.
    $processMock = $this->mock(SymphonyProcess::class, function (MockInterface $mock) use ($primaryUrl, $secondaryUrl) {
        // Mocking behavior for the first (failing) URL
        $mock->shouldReceive('fromShellCommandline')
            ->once()
            ->with(Mockery::on(function ($command) use ($primaryUrl) {
                return str_contains($command, $primaryUrl);
            }))
            ->andReturnUsing(function () use ($mock) {
                // Return a new mock instance for this specific process call or configure the existing one
                $specificProcessMock = Mockery::mock(SymphonyProcess::class . '[setTimeout,setIdleTimeout,start,getPid,getIterator,wait,isSuccessful,isRunning,stop,getErrorOutput]');
                $specificProcessMock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
                $specificProcessMock->shouldReceive('setIdleTimeout')->with(10)->andReturnSelf();
                $specificProcessMock->shouldReceive('start')->once();
                $specificProcessMock->shouldReceive('getPid')->andReturn(11111);
                $specificProcessMock->shouldReceive('getIterator')->andReturn(new ArrayIterator([])); // No data
                $specificProcessMock->shouldReceive('wait')->once();
                $specificProcessMock->shouldReceive('isSuccessful')->once()->andReturn(false); // Simulate failure
                $specificProcessMock->shouldReceive('isRunning')->andReturn(false);
                $specificProcessMock->shouldReceive('getErrorOutput')->andReturn('ffmpeg error primary');
                $specificProcessMock->shouldReceive('stop')->zeroOrMoreTimes();
                $specificProcessMock->shouldReceive('getExitCode')->andReturn(1);
                return $specificProcessMock;
            });

        // Mocking behavior for the second (successful) URL
        $mock->shouldReceive('fromShellCommandline')
            ->once()
            ->with(Mockery::on(function ($command) use ($secondaryUrl) {
                return str_contains($command, $secondaryUrl);
            }))
            ->andReturnUsing(function () use ($mock) {
                $specificProcessMock = Mockery::mock(SymphonyProcess::class . '[setTimeout,setIdleTimeout,start,getPid,getIterator,wait,isSuccessful,isRunning,stop,getErrorOutput]');
                $specificProcessMock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
                $specificProcessMock->shouldReceive('setIdleTimeout')->with(10)->andReturnSelf();
                $specificProcessMock->shouldReceive('start')->once();
                $specificProcessMock->shouldReceive('getPid')->andReturn(22222);
                $specificProcessMock->shouldReceive('getIterator')->andReturn(new ArrayIterator(['secondary_data']));
                $specificProcessMock->shouldReceive('wait')->once();
                $specificProcessMock->shouldReceive('isSuccessful')->once()->andReturn(true); // Simulate success
                $specificProcessMock->shouldReceive('isRunning')->andReturn(false);
                 $specificProcessMock->shouldReceive('stop')->zeroOrMoreTimes();
                return $specificProcessMock;
            });
    });
    $this->app->instance(SymphonyProcess::class, $processMock);


    // Log expectations
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, "Attempting to stream MergedChannel ID: {$mergedChannel->id}") && str_contains($msg, $primaryUrl)))->once();
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, "FFmpeg command for MergedChannel {$mergedChannel->id}") && str_contains($msg, $primaryUrl)))->once();
    Log::shouldReceive('channel->error')->with(Mockery::on(fn($msg) => str_contains($msg, "FFmpeg process failed for MergedChannel {$mergedChannel->id}") && str_contains($msg, $primaryUrl)))->once();
    
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, "Attempting to stream MergedChannel ID: {$mergedChannel->id}") && str_contains($msg, $secondaryUrl)))->once();
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, "FFmpeg command for MergedChannel {$mergedChannel->id}") && str_contains($msg, $secondaryUrl)))->once();
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, "Stream completed successfully for MergedChannel ID: {$mergedChannel->id}") && str_contains($msg, $secondaryUrl)))->once();
    
    Log::shouldReceive('channel->info')->zeroOrMoreTimes();
    Log::shouldReceive('channel->warning')->zeroOrMoreTimes();
    Log::shouldReceive('channel->error')->zeroOrMoreTimes();


    $response = $this->get(route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'video/MP2T');
    $response->assertSee('secondary_data');
    $response->assertDontSee('primary_data');
});

test('TestCase 3: All Sources Fail', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock GeneralSettings & Config
    $this->mock(GeneralSettings::class, function (MockInterface $mock) {
        $mock->shouldReceive('getAttribute')->with('ffmpeg_path')->andReturn('/usr/bin/ffmpeg');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_max_tries')->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_user_agent')->andReturn('TestAgent/1.0');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_video')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_audio')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_codec_subtitles')->andReturn('copy');
        $mock->shouldReceive('getAttribute')->with('ffmpeg_reconnect_delay_max')->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_stream_idle_timeout')->andReturn(10);
        $mock->shouldReceive('getAttribute')->with('ffmpeg_debug')->andReturn(false);
    });
    Config::set('proxy.ffmpeg_path', '/usr/bin/ffmpeg');
    Config::set('proxy.ffmpeg_codec_video', 'copy');
    Config::set('proxy.ffmpeg_codec_audio', 'copy');
    Config::set('proxy.ffmpeg_codec_subtitles', 'copy');
    Config::set('proxy.ffmpeg_additional_args', '');

    $url1 = 'http://fail1.test/stream.m3u8';
    $url2 = 'http://fail2.test/stream.m3u8';

    $mergedChannel = createMergedChannelWithSources($user, [
        ['url' => $url1, 'priority' => 0],
        ['url' => $url2, 'priority' => 1],
    ]);

    $processMock = $this->mock(SymphonyProcess::class, function (MockInterface $mock) use ($url1, $url2) {
        // Mocking behavior for the first failing URL
        $mock->shouldReceive('fromShellCommandline')
            ->once()
            ->with(Mockery::on(fn($cmd) => str_contains($cmd, $url1)))
             ->andReturnUsing(function () use ($mock) {
                $specificProcessMock = Mockery::mock(SymphonyProcess::class . '[setTimeout,setIdleTimeout,start,getPid,getIterator,wait,isSuccessful,isRunning,stop,getErrorOutput]');
                $specificProcessMock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
                $specificProcessMock->shouldReceive('setIdleTimeout')->with(10)->andReturnSelf();
                $specificProcessMock->shouldReceive('start')->once();
                $specificProcessMock->shouldReceive('getPid')->andReturn(33333);
                $specificProcessMock->shouldReceive('getIterator')->andReturn(new ArrayIterator([]));
                $specificProcessMock->shouldReceive('wait')->once();
                $specificProcessMock->shouldReceive('isSuccessful')->once()->andReturn(false);
                $specificProcessMock->shouldReceive('isRunning')->andReturn(false);
                $specificProcessMock->shouldReceive('getErrorOutput')->andReturn('ffmpeg error url1');
                $specificProcessMock->shouldReceive('stop')->zeroOrMoreTimes();
                $specificProcessMock->shouldReceive('getExitCode')->andReturn(1);
                return $specificProcessMock;
            });

        // Mocking behavior for the second failing URL
        $mock->shouldReceive('fromShellCommandline')
            ->once()
            ->with(Mockery::on(fn($cmd) => str_contains($cmd, $url2)))
            ->andReturnUsing(function () use ($mock) {
                $specificProcessMock = Mockery::mock(SymphonyProcess::class . '[setTimeout,setIdleTimeout,start,getPid,getIterator,wait,isSuccessful,isRunning,stop,getErrorOutput]');
                $specificProcessMock->shouldReceive('setTimeout')->with(null)->andReturnSelf();
                $specificProcessMock->shouldReceive('setIdleTimeout')->with(10)->andReturnSelf();
                $specificProcessMock->shouldReceive('start')->once();
                $specificProcessMock->shouldReceive('getPid')->andReturn(44444);
                $specificProcessMock->shouldReceive('getIterator')->andReturn(new ArrayIterator([]));
                $specificProcessMock->shouldReceive('wait')->once();
                $specificProcessMock->shouldReceive('isSuccessful')->once()->andReturn(false);
                $specificProcessMock->shouldReceive('isRunning')->andReturn(false);
                $specificProcessMock->shouldReceive('getErrorOutput')->andReturn('ffmpeg error url2');
                $specificProcessMock->shouldReceive('stop')->zeroOrMoreTimes();
                $specificProcessMock->shouldReceive('getExitCode')->andReturn(1);
                return $specificProcessMock;
            });
    });
    $this->app->instance(SymphonyProcess::class, $processMock);

    // Log expectations
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, $url1)))->twice(); // Attempting & Command
    Log::shouldReceive('channel->error')->with(Mockery::on(fn($msg) => str_contains($msg, $url1) && str_contains($msg, "FFmpeg process failed")))->once();
    Log::shouldReceive('channel->info')->with(Mockery::on(fn($msg) => str_contains($msg, $url2)))->twice(); // Attempting & Command
    Log::shouldReceive('channel->error')->with(Mockery::on(fn($msg) => str_contains($msg, $url2) && str_contains($msg, "FFmpeg process failed")))->once();
    Log::shouldReceive('channel->error')->with(Mockery::on(fn($msg) => str_contains($msg, "All source URLs failed for MergedChannel ID: {$mergedChannel->id}")))->once();
    
    Log::shouldReceive('channel->info')->zeroOrMoreTimes();
    Log::shouldReceive('channel->warning')->zeroOrMoreTimes();
    Log::shouldReceive('channel->error')->zeroOrMoreTimes();


    $response = $this->get(route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts']));

    $response->assertStatus(200); // Controller still returns 200 but with error message in body
    $response->assertSee('Error: All stream sources failed for this merged channel.');
});

test('TestCase 4: Not Found', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $nonExistentId = 9999;
    Log::shouldReceive('channel->info')->zeroOrMoreTimes();
    Log::shouldReceive('channel->warning')->zeroOrMoreTimes();
    Log::shouldReceive('channel->error')->zeroOrMoreTimes();


    $response = $this->get(route('mergedChannel.stream', ['mergedChannelId' => $nonExistentId, 'format' => 'ts']));

    $response->assertStatus(404);
});
