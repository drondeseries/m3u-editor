# FFmpeg HLS Buffering System - Manual Testing Plan

## A. Introduction

This document outlines the manual testing plan for the FFmpeg HLS Buffering System. The system consists of several Python modules designed to manage HLS (HTTP Live Streaming) streams by starting FFmpeg processes, tracking them in Redis, serving HLS files via a simple HTTP server, and performing automated cleanup of inactive or orphaned streams.

**System Components:**
*   `stream_tracker.py`: Manages stream metadata in Redis.
*   `ffmpeg_manager.py`: Controls FFmpeg processes for generating HLS streams.
*   `hls_server.py`: Serves HLS (.m3u8, .ts) files.
*   `channel_manager.py`: Acts as the entry point for client requests, orchestrating stream start-up and providing playlist URLs.
*   `stream_cleanup_service.py`: Periodically removes inactive streams and handles crashed FFmpeg processes.
*   `hls_orphan_cleanup_service.py`: Periodically removes HLS directories not tracked in Redis.

## B. Prerequisites

*   **Python**: Python 3.x installed (developed with 3.10+ in mind).
*   **Redis**: Redis server installed and running. Start with `redis-server` (default port 6379).
*   **FFmpeg**: FFmpeg installed and accessible in the system PATH.
*   **Python Scripts**:
    *   `stream_tracker.py`
    *   `ffmpeg_manager.py`
    *   `hls_server.py`
    *   `channel_manager.py`
    *   `stream_cleanup_service.py`
    *   `hls_orphan_cleanup_service.py`
*   **Directory Structure**: Ensure all Python scripts are located in the same directory, or that PYTHONPATH is configured appropriately.
*   **HLS Base Directory**: The default HLS output path is `/mnt/hls_streams`. Ensure this directory exists and is writable by the user running the scripts. If not, modify `HLS_BASE_PATH` in `ffmpeg_manager.py` and update dependent configurations.
*   **Example Stream URL**: A publicly accessible HLS stream URL is needed for testing.
    *   Example: `http://devimages.apple.com/iphone/samples/bipbop/bipbopall.m3u8` (Apple's BipBop test stream). Replace with any valid HLS stream if this is unavailable.

## C. Setup Instructions

1.  **Start Redis Server**:
    *   Open a terminal and run: `redis-server`
    *   Verify it starts without errors.

2.  **Start System Services**: Open separate terminal windows for each service. Execute them in the directory containing the scripts.

    *   **Terminal 1: HLS Server**
        ```bash
        python hls_server.py
        ```
        Note the host and port it reports (e.g., `0.0.0.0:8080`).

    *   **Terminal 2: Stream Cleanup Service**
        ```bash
        python stream_cleanup_service.py
        ```

    *   **Terminal 3: HLS Orphan Cleanup Service**
        ```bash
        python hls_orphan_cleanup_service.py
        ```

3.  **Verify Service Startup**:
    *   Check the terminal output for each service. They should log successful startup messages without immediate errors.
    *   For example, `hls_server.py` should log "HLS Server started successfully...".
    *   The cleanup services should log their configuration and startup messages.

## D. Test Scenarios

**Important:** For actions involving `channel_manager.py`, you can create a small Python test script or use Python's interactive mode. Example test script (`run_channel_request.py`):

```python
# run_channel_request.py
import sys
from channel_manager import get_hls_playlist_for_channel, HLS_SERVER_HOST, HLS_SERVER_PORT
import logging

# Configure logging to see output from channel_manager and its dependencies
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(module)s - %(message)s')

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python run_channel_request.py <original_stream_url>")
        sys.exit(1)
    
    original_url = sys.argv[1]
    print(f"Requesting HLS playlist for: {original_url}")
    playlist_url = get_hls_playlist_for_channel(original_url)
    
    if playlist_url:
        print(f"Success! Public HLS Playlist URL: {playlist_url}")
        print(f"You can try opening this URL in VLC, ffplay, or an HLS test player.")
    else:
        print("Failed to get HLS playlist URL. Check logs for details.")

```

### Test Case 1: First Client Request & Stream Start-up

*   **Objective**: Verify that a request for a new stream URL correctly starts an FFmpeg process, creates HLS files, updates Redis, and returns a valid public playlist URL.
*   **Action(s)**:
    1.  Use a test script (like `run_channel_request.py` above) or Python interactive mode to call `channel_manager.get_hls_playlist_for_channel("http://devimages.apple.com/iphone/samples/bipbop/bipbopall.m3u8")`.
*   **Expected Outcome(s)**:
    1.  A public HLS playlist URL is returned by `channel_manager.py` (e.g., `http://localhost:8080/stream:<hash>/playlist.m3u8`).
    2.  An FFmpeg process is started for the given URL.
    3.  A new directory named `stream:<hash>` is created under `HLS_BASE_PATH` (e.g., `/mnt/hls_streams/stream:<hash>`).
    4.  This directory contains `playlist.m3u8`, `ffmpeg.log`, and `.ts` segment files.
    5.  A new entry with key `stream:<hash>` is created in Redis containing stream metadata (URL, PID, HLS path, timestamps).
    6.  Logs in `ffmpeg_manager.py` and `channel_manager.py` indicate successful stream startup.
*   **Verification**:
    1.  Check the returned URL.
    2.  Use `ps aux | grep ffmpeg` (or `pgrep ffmpeg`) to find the FFmpeg process. Note its PID.
    3.  Use `ls -l /mnt/hls_streams/stream:<hash>/` to check for HLS files.
    4.  Use `redis-cli` to inspect the Redis entry:
        *   `redis-cli KEYS "stream:*"` (to find the key)
        *   `redis-cli HGETALL "stream:<hash>"` (to view its content, verify PID matches).
    5.  Review logs of `channel_manager.py` and `ffmpeg_manager.py` (if they log to console/files).
    6.  Try playing the returned public HLS URL in VLC or `ffplay`.

### Test Case 2: Subsequent Client Request (Cache Hit & Activity Update)

*   **Objective**: Verify that a subsequent request for an already active stream returns the cached URL and updates its `last_activity_timestamp`.
*   **Action(s)**:
    1.  After Test Case 1 is successful, wait for ~10-15 seconds.
    2.  Call `channel_manager.get_hls_playlist_for_channel("http://devimages.apple.com/iphone/samples/bipbop/bipbopall.m3u8")` again.
*   **Expected Outcome(s)**:
    1.  The same public HLS playlist URL as in Test Case 1 is returned quickly.
    2.  No new FFmpeg process is started.
    3.  The `last_activity_timestamp` for the stream in Redis is updated to a more recent time.
    4.  Logs in `channel_manager.py` indicate a cache hit.
*   **Verification**:
    1.  Compare the returned URL with the one from Test Case 1.
    2.  Use `redis-cli HGETALL "stream:<hash>"` and note the `last_activity_timestamp`.
    3.  Call the function again after a few seconds and re-check the timestamp; it should have increased.
    4.  Verify no new FFmpeg processes were started (`ps aux | grep ffmpeg`).

### Test Case 3: Stream Termination due to Inactivity

*   **Objective**: Verify that `stream_cleanup_service.py` detects and cleans up an inactive stream.
*   **Prerequisites**:
    *   Set `INACTIVITY_TIMEOUT_SECONDS` in `stream_cleanup_service.py` to a short duration (e.g., 30-60 seconds) for testing.
    *   Set `CLEANUP_INTERVAL_SECONDS` to a short duration (e.g., 20-30 seconds). Restart the service.
*   **Action(s)**:
    1.  Start a stream using `channel_manager.get_hls_playlist_for_channel(...)` (as in Test Case 1).
    2.  Do **not** make any further requests for this stream.
    3.  Wait for `INACTIVITY_TIMEOUT_SECONDS` + `CLEANUP_INTERVAL_SECONDS` + a small buffer (e.g., total 90-120 seconds if timeout is 60s and interval is 30s).
*   **Expected Outcome(s)**:
    1.  The FFmpeg process associated with the stream is terminated.
    2.  The HLS directory (`/mnt/hls_streams/stream:<hash>`) is deleted.
    3.  The stream entry in Redis (`stream:<hash>`) is removed.
    4.  Logs in `stream_cleanup_service.py` show the stream being identified as inactive and cleaned up.
*   **Verification**:
    1.  Use `ps aux | grep ffmpeg` to confirm the FFmpeg process is gone.
    2.  Use `ls -l /mnt/hls_streams/` to confirm the directory is deleted.
    3.  Use `redis-cli KEYS "stream:*"` to confirm the Redis key is removed.
    4.  Check logs of `stream_cleanup_service.py`.

### Test Case 4: FFmpeg Process Crash & Self-Healing

*   **Objective**: Verify that `stream_cleanup_service.py` detects an FFmpeg process that crashed (is no longer running) even if its Redis entry exists, and cleans it up.
*   **Prerequisites**:
    *   Set `CLEANUP_INTERVAL_SECONDS` in `stream_cleanup_service.py` to a short duration (e.g., 30 seconds). Restart the service.
*   **Action(s)**:
    1.  Start a stream (Test Case 1). Note the FFmpeg PID and `channel_id`.
    2.  Manually kill the FFmpeg process: `kill -9 <PID>`. **Do not** kill the Python services.
    3.  Wait for the next cleanup cycle (e.g., 30-40 seconds).
*   **Expected Outcome(s)**:
    1.  `stream_cleanup_service.py` detects the PID is missing.
    2.  The HLS directory for the crashed stream is deleted.
    3.  The stream entry in Redis is removed.
    4.  Logs in `stream_cleanup_service.py` indicate cleanup of a crashed/orphaned PID.
*   **Verification**:
    1.  Confirm FFmpeg process is gone (`ps aux | grep ffmpeg`).
    2.  Check HLS directory is deleted.
    3.  Check Redis key is removed.
    4.  Review `stream_cleanup_service.py` logs for messages about "PID not found" or "crashed process".

### Test Case 5: Orphaned HLS Directory Cleanup

*   **Objective**: Verify that `hls_orphan_cleanup_service.py` detects and removes HLS directories that exist on the filesystem but have no corresponding entry in Redis.
*   **Prerequisites**:
    *   Set `MAX_ORPHAN_AGE_SECONDS` in `hls_orphan_cleanup_service.py` to a short duration (e.g., 30 seconds, or 0 to disable age check for faster testing).
    *   Set `CLEANUP_INTERVAL_SECONDS` to a short duration (e.g., 30-40 seconds). Restart the service.
*   **Action(s)**:
    1.  Start a stream (Test Case 1). Note its `channel_id` (e.g., `stream:<hash>`).
    2.  Manually remove ONLY the Redis entry for this stream: `redis-cli DEL stream:<hash>`.
    3.  The HLS directory `/mnt/hls_streams/stream:<hash>/` still exists, and FFmpeg might still be running (this is okay for this test).
    4.  Wait for the next orphan cleanup cycle.
    *(Alternative orphan creation: Manually create a directory like `/mnt/hls_streams/stream:fakeorphan123` and put some dummy files in it. Wait for `MAX_ORPHAN_AGE_SECONDS` if enabled.)*
*   **Expected Outcome(s)**:
    1.  `hls_orphan_cleanup_service.py` identifies the directory as an orphan.
    2.  The orphaned HLS directory is deleted from the filesystem.
    3.  Logs in `hls_orphan_cleanup_service.py` indicate detection and deletion of an orphaned directory.
    4.  If FFmpeg was still running for this stream, it will eventually be handled by `stream_cleanup_service` as a crashed process (since its Redis entry is gone) or might continue running until manually stopped if its Redis entry was the only thing removed. For this test, focus on directory removal.
*   **Verification**:
    1.  Check that the HLS directory is deleted.
    2.  Review `hls_orphan_cleanup_service.py` logs.

### Test Case 6: Requesting an Invalid/Unreachable Stream URL

*   **Objective**: Verify that `channel_manager.py` and `ffmpeg_manager.py` handle requests for invalid or unreachable stream URLs gracefully.
*   **Action(s)**:
    1.  Call `channel_manager.get_hls_playlist_for_channel("http://thisshouldnotexist.invalid/stream.m3u8")`.
*   **Expected Outcome(s)**:
    1.  `get_hls_playlist_for_channel` returns `None`.
    2.  No new FFmpeg process is left running for this URL.
    3.  No stream entry is created in Redis for this URL.
    4.  No HLS directory is permanently created (it might be temporarily created and then cleaned up by `ffmpeg_manager.py`).
    5.  Logs in `ffmpeg_manager.py` (e.g., `ffmpeg.log` within a temporary HLS folder, or console logs) should indicate FFmpeg failure. `channel_manager.py` logs should indicate failure to start the stream.
*   **Verification**:
    1.  Check the return value.
    2.  Verify no new, persistent FFmpeg processes related to this URL (`ps aux | grep ffmpeg`).
    3.  Check Redis (`redis-cli KEYS "stream:*"`) for any new entries related to this URL.
    4.  Check `HLS_BASE_PATH` for any new, persistent directories.
    5.  Review logs.

### Test Case 7: (Optional) Multiple Different Streams Active Simultaneously

*   **Objective**: Verify the system can handle multiple active streams concurrently.
*   **Action(s)**:
    1.  Request stream A (e.g., `bipbopall.m3u8`).
    2.  Request stream B (e.g., another public HLS test stream, if available. If not, use the same URL but the system should treat it as the same stream unless `channel_id` generation is path-dependent and distinct). For true concurrency, distinct source URLs are best.
*   **Expected Outcome(s)**:
    1.  Both streams start and have their respective FFmpeg processes, HLS directories, and Redis entries.
    2.  Both return valid public playlist URLs.
*   **Verification**:
    1.  Verify PIDs, HLS directories, and Redis entries for both streams.
    2.  Attempt to play both streams.

### Test Case 8: (Optional) HLS Server Access & MIME Types

*   **Objective**: Verify `hls_server.py` serves files with correct MIME types and restricts access.
*   **Action(s)**:
    1.  Start a stream (Test Case 1) and get its public URL (e.g., `http://localhost:8080/stream:<hash>/playlist.m3u8`).
    2.  Access `http://localhost:8080/stream:<hash>/playlist.m3u8` using `curl -I` or a web browser's developer tools.
    3.  Access a segment file like `http://localhost:8080/stream:<hash>/segment001.ts` (if it exists) using `curl -I` or browser tools.
    4.  Attempt to access a non-existent file: `http://localhost:8080/stream:<hash>/nonexistent.m3u8`.
    5.  Attempt to access a file outside HLS_BASE_PATH (conceptual, as server should prevent this): `http://localhost:8080/../../etc/passwd`.
    6.  Attempt directory listing: `http://localhost:8080/stream:<hash>/`.
*   **Expected Outcome(s)**:
    1.  `playlist.m3u8`: `Content-Type: application/vnd.apple.mpegurl`. HTTP 200.
    2.  `segmentXXX.ts`: `Content-Type: video/mp2t`. HTTP 200.
    3.  Non-existent file: HTTP 404.
    4.  Outside path / directory listing: HTTP 403 Forbidden.
*   **Verification**:
    1.  Check HTTP response headers (Content-Type) and status codes from `curl` or browser tools.
    2.  Review `hls_server.py` logs.

## E. Cleanup After Testing

1.  **Stop Services**: Press `Ctrl+C` in each terminal window where the Python services (`hls_server.py`, `stream_cleanup_service.py`, `hls_orphan_cleanup_service.py`) are running.
2.  **Stop FFmpeg Processes**: If any FFmpeg processes were manually started or not cleaned up by services, stop them:
    *   `pgrep ffmpeg` to list PIDs.
    *   `pkill ffmpeg` or `kill <PID>` for each.
3.  **Flush Redis**: (Optional, if you want a clean Redis for other uses or re-testing)
    *   `redis-cli FLUSHDB` (flushes current DB) or `redis-cli FLUSHALL` (flushes all DBs). **Caution**: This deletes all data in the Redis instance.
4.  **Clean HLS Directories**: Manually delete any remaining stream directories under `HLS_BASE_PATH` (e.g., `/mnt/hls_streams/`) if they were not automatically cleaned up.
    ```bash
    # Example: sudo rm -rf /mnt/hls_streams/stream:*
    ```
    **Caution**: Be careful with `rm -rf`. Ensure the path is correct.

## F. Notes on Automated Testing (Future Consideration)

For continuous development and more reliable testing, implementing automated tests is highly recommended:

*   **Unit Tests**: Use frameworks like `unittest` (built-in) or `pytest` to test individual functions and classes in isolation. This would involve:
    *   Mocking external dependencies (Redis, FFmpeg subprocesses) using `unittest.mock` or `pytest-mock`. For example, `stream_tracker.py` functions can be tested by mocking `redis.Redis`. `ffmpeg_manager.py` can mock `subprocess.Popen`.
*   **Integration Tests**: `pytest` can also be used to test interactions between modules, potentially with a real (but controlled) Redis instance and mocked FFmpeg execution.
*   **End-to-End (E2E) Tests**: More complex, could involve scripting interactions with a fully running system, possibly using tools like `requests` to interact with `hls_server.py` and `channel_manager.py`.
*   **Docker**: Using Docker and Docker Compose can help create consistent, isolated environments for running the system and its dependencies (Redis, FFmpeg in a container), making testing more reproducible, especially in CI/CD pipelines.
*   **CI/CD**: Integrate automated tests into a Continuous Integration/Continuous Deployment pipeline (e.g., GitHub Actions, Jenkins) to automatically test changes before deployment.

This manual testing plan provides a baseline for verifying system functionality. As the system evolves, this plan should be updated, and automation should be progressively introduced.
```
