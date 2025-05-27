<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\FailoverStreamController;
use App\Models\FailoverChannel;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\HlsStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FailoverStreamControllerTest extends TestCase
{
    use RefreshDatabase; // Using RefreshDatabase for simplicity, though mocks are primary

    protected MockInterface $hlsStreamServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock HlsStreamService
        $this->hlsStreamServiceMock = Mockery::mock(HlsStreamService::class);
        $this->app->instance(HlsStreamService::class, $this->hlsStreamServiceMock);

        // Mock Facades
        Cache::shouldReceive('get')->byDefault()->andReturnNull();
        Cache::shouldReceive('put')->byDefault()->andReturnTrue();
        Cache::shouldReceive('forget')->byDefault()->andReturnTrue();

        Storage::shouldReceive('disk')->with('app')->andReturnSelf()
            ->shouldReceive('path')->byDefault()->andReturnUsing(function ($path) {
                return '/mocked/storage/' . $path;
            });

        Log::shouldReceive('channel')->with('ffmpeg')->andReturnSelf()
            ->shouldReceive('info')->byDefault()
            ->shouldReceive('warning')->byDefault()
            ->shouldReceive('error')->byDefault();

        // Mock file_exists - this is a global function, so it's harder to mock directly
        // For tests needing file_exists, we'll rely on the HlsStreamService mock or direct logic flow.
        // If direct file_exists mocking is critical, a helper function or a library might be needed,
        // but for these tests, we'll try to control flow via service mocks.
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function mockFailoverChannel(array $sourcesData = []): MockInterface
    {
        $failoverChannelMock = Mockery::mock(FailoverChannel::class)->makePartial();
        $failoverChannelMock->id = rand(1, 1000);
        $failoverChannelMock->name = 'Test Failover Channel';

        $sourceMocks = collect();
        foreach ($sourcesData as $index => $data) {
            $playlistMock = Mockery::mock(Playlist::class);
            $playlistMock->user_agent = $data['user_agent'] ?? 'TestAgent/1.0';

            $channelMock = Mockery::mock(Channel::class)->makePartial();
            $channelMock->id = $data['id'];
            $channelMock->url_custom = $data['url'] ?? null;
            $channelMock->url = $data['url'] ?? 'http://example.com/stream' . $channelMock->id;
            $channelMock->title_custom = $data['title'] ?? null;
            $channelMock->title = $data['title'] ?? 'Source ' . $channelMock->id;
            $channelMock->name = $data['name'] ?? 'Source ' . $channelMock->id;
            $channelMock->enabled = $data['enabled'] ?? true;
            $channelMock->pivot_order = $data['pivot_order'] ?? $index; // Simulate pivot_order

            $channelMock->shouldReceive('playlist')->andReturn($playlistMock);
            $sourceMocks->push($channelMock);
        }
        
        // Mock the relationship chain
        $failoverChannelMock->shouldReceive('sources')
            ->andReturnSelf() // sources() returns the relationship object
            ->shouldReceive('where')
            ->with('channels.enabled', true)
            ->andReturnSelf() // where() returns the relationship object
            ->shouldReceive('orderBy')
            ->with('pivot_order', 'asc')
            ->andReturnSelf() // orderBy() returns the relationship object
            ->shouldReceive('get')
            ->andReturn($sourceMocks); // get() returns the collection of sources

        return $failoverChannelMock;
    }

    // Test 1: No Enabled Sources
    public function testServeHlsPlaylistWithNoEnabledSources()
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $failoverChannel = $this->mockFailoverChannel([]); // No sources

        $controller = $this->app->make(FailoverStreamController::class);
        $request = Request::create('/failover/test.m3u8', 'GET');

        $controller->serveHlsPlaylist($request, $failoverChannel);
    }

    // Test 2: Initial Source Selection and Session Creation
    public function testServeHlsPlaylistInitialSourceSelectionAndSessionCreation()
    {
        $source1Data = ['id' => 101, 'url' => 'http://source1.test/stream', 'title' => 'Source 1', 'pivot_order' => 0];
        $source2Data = ['id' => 102, 'url' => 'http://source2.test/stream', 'title' => 'Source 2', 'pivot_order' => 1];
        $failoverChannel = $this->mockFailoverChannel([$source1Data, $source2Data]);
        $mockPid = 12345;

        Cache::shouldReceive('get')
            ->once()
            ->with('hls:failover_session:' . $failoverChannel->id)
            ->andReturnNull();

        $this->hlsStreamServiceMock->shouldReceive('isRunning')
            ->never(); // Should not be called if no existing PID in session

        $this->hlsStreamServiceMock->shouldReceive('startStream')
            ->once()
            ->with('channel', $source1Data['id'], $source1Data['url'], $source1Data['title'], 'TestAgent/1.0')
            ->andReturn($mockPid);
        
        // Mock stopStream to ensure it's NOT called in this initial scenario for a different source
        $this->hlsStreamServiceMock->shouldReceive('stopStream')->never();

        Storage::shouldReceive('disk->path')
            ->once()
            ->with("hls/{$source1Data['id']}/stream.m3u8")
            ->andReturn("/mocked/storage/hls/{$source1Data['id']}/stream.m3u8");
        
        // We need a way to make file_exists return true.
        // This is tricky. For now, let's assume startStream implies playlist will be available.
        // The controller has a loop `for ($i = 0; $i < 10; $i++)`.
        // We'll mock isRunning to keep returning true for the new PID during this loop.
        $this->hlsStreamServiceMock->shouldReceive('isRunning')
             ->with('channel', $source1Data['id']) // This will be checked inside the wait loop
             ->andReturnTrue();


        Cache::shouldReceive('put')
            ->once()
            ->with(
                'hls:failover_session:' . $failoverChannel->id,
                Mockery::on(function ($data) use ($source1Data, $mockPid) {
                    return isset($data['sources_list']) &&
                           count($data['sources_list']) === 2 &&
                           $data['sources_list'][0]['id'] === $source1Data['id'] &&
                           $data['current_source_index'] === 0 &&
                           $data['current_source_channel_id'] === $source1Data['id'] &&
                           $data['current_ffmpeg_pid'] === $mockPid;
                }),
                Mockery::any() // or specific Carbon instance for TTL
            )->andReturnTrue();

        $controller = $this->app->make(FailoverStreamController::class);
        $request = Request::create('/failover/test.m3u8', 'GET');

        // Simulate file_exists returning true after startStream
        // This is a simplification. In a real scenario, the file appears due to ffmpeg.
        // We will rely on the HlsStreamService mock for isRunning to control the loop.
        // And then directly check the response.
        // A more robust way would be to mock a global file_exists if possible or refactor for testability.
        
        // Temporarily allow file_exists to be mocked (requires a helper or specific setup)
        // For this test, we'll assume that if startStream succeeds and isRunning is true,
        // the controller's internal file_exists check will pass after some loop iterations.
        // The most important part is that it tries to serve the correct redirect.

        $response = $controller->serveHlsPlaylist($request, $failoverChannel);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            "/internal/hls/{$source1Data['id']}/stream.m3u8",
            $response->headers->get('X-Accel-Redirect')
        );
    }

    // Test 3: Uses Existing Healthy Session
    public function testServeHlsPlaylistUsesExistingHealthySession()
    {
        $source1Data = ['id' => 201, 'url' => 'http://sourceA.test/stream', 'title' => 'Source A'];
        $source2Data = ['id' => 202, 'url' => 'http://sourceB.test/stream', 'title' => 'Source B'];
        $failoverChannel = $this->mockFailoverChannel([$source1Data, $source2Data]);
        $existingPid = 54321;

        $sessionData = [
            'sources_list' => [
                ['id' => $source1Data['id'], 'url' => $source1Data['url'], 'user_agent' => 'TestAgent/1.0', 'title' => $source1Data['title']],
                ['id' => $source2Data['id'], 'url' => $source2Data['url'], 'user_agent' => 'TestAgent/1.0', 'title' => $source2Data['title']],
            ],
            'current_source_index' => 0,
            'current_source_channel_id' => $source1Data['id'],
            'current_ffmpeg_pid' => $existingPid,
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with('hls:failover_session:' . $failoverChannel->id)
            ->andReturn($sessionData);

        $this->hlsStreamServiceMock->shouldReceive('isRunning')
            ->once()
            ->with('channel', $source1Data['id']) // Check for the current source in session
            ->andReturnTrue(); // Simulate it's healthy

        $this->hlsStreamServiceMock->shouldReceive('startStream')->never(); // Should not start a new stream
        $this->hlsStreamServiceMock->shouldReceive('stopStream')->never();


        Storage::shouldReceive('disk->path')
            ->once()
            ->with("hls/{$source1Data['id']}/stream.m3u8")
            ->andReturn("/mocked/storage/hls/{$source1Data['id']}/stream.m3u8");
        
        // Cache::put will be called to update TTL or re-save session
        Cache::shouldReceive('put')
            ->once()
            ->with(
                'hls:failover_session:' . $failoverChannel->id,
                $sessionData, // Expecting the same data to be put back
                Mockery::any()
            )->andReturnTrue();


        $controller = $this->app->make(FailoverStreamController::class);
        $request = Request::create('/failover/test.m3u8', 'GET');
        
        // Similar to Test 2, assuming file_exists will pass due to isRunning being true.
        $response = $controller->serveHlsPlaylist($request, $failoverChannel);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            "/internal/hls/{$source1Data['id']}/stream.m3u8",
            $response->headers->get('X-Accel-Redirect')
        );
    }

    // Test 4: Failover to Next Source if PID Died
    public function testServeHlsPlaylistFailoverToNextSourceIfPidDied()
    {
        $sourceAData = ['id' => 301, 'url' => 'http://sourceA.test/stream', 'title' => 'Source A', 'user_agent' => 'AgentA', 'pivot_order' => 0];
        $sourceBData = ['id' => 302, 'url' => 'http://sourceB.test/stream', 'title' => 'Source B', 'user_agent' => 'AgentB', 'pivot_order' => 1];
        $failoverChannel = $this->mockFailoverChannel([$sourceAData, $sourceBData]);
        
        $pidSourceA = 123;
        $newPidSourceB = 456;

        $initialSessionData = [
            'sources_list' => [
                ['id' => $sourceAData['id'], 'url' => $sourceAData['url'], 'user_agent' => $sourceAData['user_agent'], 'title' => $sourceAData['title']],
                ['id' => $sourceBData['id'], 'url' => $sourceBData['url'], 'user_agent' => $sourceBData['user_agent'], 'title' => $sourceBData['title']],
            ],
            'current_source_index' => 0,
            'current_source_channel_id' => $sourceAData['id'],
            'current_ffmpeg_pid' => $pidSourceA,
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with('hls:failover_session:' . $failoverChannel->id)
            ->andReturn($initialSessionData);

        // First isRunning check for Source A (from session) - it died
        $this->hlsStreamServiceMock->shouldReceive('isRunning')
            ->once()
            ->with('channel', $sourceAData['id'])
            ->andReturnFalse();

        // Expect stopStream for the old source IF that logic is present (it was mentioned in the implementation)
        // The implementation has: if ($pid !== null && $sessionData['current_source_channel_id'] !== $currentSource['id'])
        // This condition might not be met if the PID died for the *current* source in session.
        // The logic is `if ($pid !== null)` (true) `&& $this->hlsService->isRunning(...)` (false) -> enters `else` block
        // Inside else, `currentSourceIndex++`. Then `currentSource` becomes B.
        // Then `if ($pid !== null && $sessionData['current_source_channel_id'] !== $currentSource['id'])`
        // $pid is $pidSourceA (123). $sessionData['current_source_channel_id'] is $sourceAData['id']. $currentSource['id'] is $sourceBData['id'].
        // So, $sourceAData['id'] !== $sourceBData['id'] is true. This means stopStream should be called.
        $this->hlsStreamServiceMock->shouldReceive('stopStream')
             ->once()
             ->with('channel', $sourceAData['id']) // old source ID from session
             ->andReturnTrue();


        // Expect startStream for Source B
        $this->hlsStreamServiceMock->shouldReceive('startStream')
            ->once()
            ->with('channel', $sourceBData['id'], $sourceBData['url'], $sourceBData['title'], $sourceBData['user_agent'])
            ->andReturn($newPidSourceB);

        Storage::shouldReceive('disk->path')
            ->once()
            ->with("hls/{$sourceBData['id']}/stream.m3u8")
            ->andReturn("/mocked/storage/hls/{$sourceBData['id']}/stream.m3u8");

        // isRunning check for Source B during the playlist wait loop
        $this->hlsStreamServiceMock->shouldReceive('isRunning')
             ->with('channel', $sourceBData['id']) // This will be checked inside the wait loop for source B
             ->andReturnTrue();

        Cache::shouldReceive('put')
            ->once()
            ->with(
                'hls:failover_session:' . $failoverChannel->id,
                Mockery::on(function ($data) use ($sourceBData, $newPidSourceB) {
                    return isset($data['sources_list']) &&
                           count($data['sources_list']) === 2 &&
                           $data['sources_list'][1]['id'] === $sourceBData['id'] &&
                           $data['current_source_index'] === 1 && // Index updated to 1
                           $data['current_source_channel_id'] === $sourceBData['id'] && // Channel ID updated
                           $data['current_ffmpeg_pid'] === $newPidSourceB; // PID updated
                }),
                Mockery::any()
            )->andReturnTrue();

        $controller = $this->app->make(FailoverStreamController::class);
        $request = Request::create('/failover/test.m3u8', 'GET');
        
        $response = $controller->serveHlsPlaylist($request, $failoverChannel);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            "/internal/hls/{$sourceBData['id']}/stream.m3u8",
            $response->headers->get('X-Accel-Redirect')
        );
    }
}
