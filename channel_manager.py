"""
Manages client requests for HLS streams, integrating stream_tracker and ffmpeg_manager.
"""
import os
import logging
import stream_tracker
import ffmpeg_manager # To access HLS_BASE_PATH and start_master_stream

# --- Configuration ---
HLS_SERVER_HOST = "localhost" # Host where hls_server.py is accessible
HLS_SERVER_PORT = 8080        # Port where hls_server.py is accessible

# Assumes ffmpeg_manager.HLS_BASE_PATH is the source of truth for HLS file locations.
# This path needs to be consistent with what hls_server.py serves.
FM_HLS_BASE_PATH = ffmpeg_manager.HLS_BASE_PATH
# --- End Configuration ---

# Configure basic logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(module)s - %(message)s')


def _ensure_redis_connection():
    """
    Ensures that the Redis connection in stream_tracker is available.
    This is a helper to be called at the beginning of public functions if needed,
    or rely on stream_tracker's own connection management.
    """
    if not stream_tracker._get_redis_connection():
        logging.error("Failed to establish Redis connection via stream_tracker.")
        # Depending on desired strictness, could raise an exception.
        # For now, functions will likely fail gracefully if Redis is down.
        return False
    return True

def _construct_public_hls_url(hls_playlist_file_path: str) -> str | None:
    """
    Constructs a publicly accessible HLS playlist URL from an absolute file path.

    Args:
        hls_playlist_file_path: Absolute path to the .m3u8 file
                                (e.g., /mnt/hls_streams/channel123/playlist.m3u8).

    Returns:
        The public URL (e.g., http://localhost:8080/channel123/playlist.m3u8)
        or None if the path is not valid or not under FM_HLS_BASE_PATH.
    """
    abs_hls_playlist_path = os.path.abspath(hls_playlist_file_path)
    abs_fm_hls_base_path = os.path.abspath(FM_HLS_BASE_PATH)

    if not abs_hls_playlist_path.startswith(abs_fm_hls_base_path):
        logging.error(f"HLS playlist path '{abs_hls_playlist_path}' is not within "
                      f"the HLS base path '{abs_fm_hls_base_path}'. Cannot construct URL.")
        return None

    # Make path relative to HLS_BASE_PATH
    # Example: /mnt/hls_streams/channel123/playlist.m3u8 -> channel123/playlist.m3u8
    relative_path = os.path.relpath(abs_hls_playlist_path, abs_fm_hls_base_path)

    # Ensure no ".." components, though relpath from a subpath should be fine.
    # And ensure it's not trying to go outside by using abspath comparison earlier.
    if ".." in relative_path:
        logging.error(f"Generated relative path '{relative_path}' contains '..'. Path rejected.")
        return None
        
    # Normalize to use forward slashes for URL
    relative_url_path = relative_path.replace(os.path.sep, '/')

    public_url = f"http://{HLS_SERVER_HOST}:{HLS_SERVER_PORT}/{relative_url_path}"
    logging.debug(f"Constructed public URL: {public_url} from path: {hls_playlist_file_path}")
    return public_url


if __name__ == '__main__':
    # Test for _construct_public_hls_url
    print("--- Testing _construct_public_hls_url ---")
    
    # Mocking ffmpeg_manager.HLS_BASE_PATH for standalone test if needed, but here we use the imported one
    print(f"Using FM_HLS_BASE_PATH: {FM_HLS_BASE_PATH}")

    valid_path = os.path.join(FM_HLS_BASE_PATH, "channelxyz", "playlist.m3u8")
    url = _construct_public_hls_url(valid_path)
    expected_url = f"http://{HLS_SERVER_HOST}:{HLS_SERVER_PORT}/channelxyz/playlist.m3u8"
    assert url == expected_url, f"Expected {expected_url}, got {url}"
    print(f"Valid path test: {valid_path} -> {url} (Correct)")

    # Test with path normalization
    valid_path_unnormalized = os.path.join(FM_HLS_BASE_PATH, "channelxyz", ".", "playlist.m3u8")
    url_norm = _construct_public_hls_url(valid_path_unnormalized)
    assert url_norm == expected_url, f"Expected {expected_url} for unnormalized, got {url_norm}"
    print(f"Unnormalized path test: {valid_path_unnormalized} -> {url_norm} (Correct)")


    invalid_path_outside = "/etc/passwd"
    url_outside = _construct_public_hls_url(invalid_path_outside)
    assert url_outside is None, f"Expected None for path outside base, got {url_outside}"
    print(f"Path outside base test: {invalid_path_outside} -> {url_outside} (Correct)")

    invalid_path_above = os.path.join(os.path.dirname(abs_fm_hls_base_path), "somefile.m3u8")
    url_above = _construct_public_hls_url(invalid_path_above)
    assert url_above is None, f"Expected None for path above base, got {url_above}"
    print(f"Path above base test: {invalid_path_above} -> {url_above} (Correct)")
    
    # Test with path that might try to use ".." but os.path.relpath might handle it well
    # if it's still under the base path after normalization. The key is the startswith check.
    tricky_path = os.path.join(FM_HLS_BASE_PATH, "channel1", "..", "channel1", "playlist.m3u8")
    # This normalizes to /mnt/hls_streams/channel1/playlist.m3u8
    url_tricky = _construct_public_hls_url(tricky_path)
    expected_tricky_url = f"http://{HLS_SERVER_HOST}:{HLS_SERVER_PORT}/channel1/playlist.m3u8"
    assert url_tricky == expected_tricky_url, f"Expected {expected_tricky_url}, got {url_tricky}"
    print(f"Tricky path (normalized under base) test: {tricky_path} -> {url_tricky} (Correct)")

    print("--- _construct_public_hls_url tests passed ---")

    # Placeholder for get_hls_playlist_for_channel and its tests
    # These will be added in the next step.

def get_hls_playlist_for_channel(original_url: str) -> str | None:
    """
    Retrieves or starts an HLS stream for the given original_url and returns a public playlist URL.

    Args:
        original_url: The URL of the source video stream.

    Returns:
        The publicly accessible HLS playlist URL as a string on success, or None on failure.
    """
    if not _ensure_redis_connection():
        # _ensure_redis_connection already logs the error
        return None

    if not original_url:
        logging.error("Original URL cannot be empty.")
        return None

    channel_id = stream_tracker.generate_channel_id(original_url)
    logging.info(f"Request for original_url: '{original_url}' (Channel ID: {channel_id})")

    stream_info = stream_tracker.get_stream(channel_id)

    if stream_info:
        logging.info(f"Cache hit for channel_id: {channel_id}. Stream already exists.")
        if not stream_tracker.update_stream_activity(channel_id):
            # Log this failure but proceed; the stream still exists.
            logging.warning(f"Failed to update stream activity for channel_id: {channel_id}, but stream exists.")
        
        hls_playlist_path = stream_info.get('hls_playlist_path')
        if not hls_playlist_path:
            logging.error(f"Stream {channel_id} found in tracker but 'hls_playlist_path' is missing. Data corruption?")
            # This indicates a problem with the data in Redis or how it was stored.
            # For robustness, one might try to "repair" by restarting the stream,
            # but for now, we'll treat it as a failure for this request.
            return None
            
        public_url = _construct_public_hls_url(hls_playlist_path)
        if public_url:
            logging.info(f"Returning existing stream URL for {channel_id}: {public_url}")
            return public_url
        else:
            # This case implies hls_playlist_path was somehow not under FM_HLS_BASE_PATH,
            # which would be unusual if ffmpeg_manager created it correctly.
            logging.error(f"Failed to construct public URL for existing stream {channel_id} with path {hls_playlist_path}.")
            return None
    else:
        logging.info(f"Cache miss for channel_id: {channel_id}. Attempting to start new stream.")
        # Stream does not exist, try to start it
        # Note: ffmpeg_manager.start_master_stream is responsible for adding the stream to stream_tracker
        # if it starts successfully.
        hls_playlist_file_path = ffmpeg_manager.start_master_stream(original_url, channel_id)

        if hls_playlist_file_path:
            logging.info(f"Successfully started new FFmpeg stream for {channel_id}. Playlist at: {hls_playlist_file_path}")
            public_url = _construct_public_hls_url(hls_playlist_file_path)
            if public_url:
                logging.info(f"Returning new stream URL for {channel_id}: {public_url}")
                return public_url
            else:
                # This would be an issue if start_master_stream returns a path not in HLS_BASE_PATH
                logging.error(f"Failed to construct public URL for newly started stream {channel_id} with path {hls_playlist_file_path}.")
                # Stream might be running, but we can't serve it. This is a critical error.
                # Consider stopping the stream if it's guaranteed start_master_stream added it to tracker
                # and we have a way to stop it. For now, this path indicates an inconsistency.
                return None
        else:
            logging.error(f"Failed to start FFmpeg stream for original_url: '{original_url}' (channel_id: {channel_id}).")
            return None

if __name__ == '__main__':
    # This block serves as an integration test suite for channel_manager.py.
    # It requires:
    # 1. Redis server running and accessible.
    # 2. FFmpeg installed and in PATH (for starting actual streams).
    # 3. stream_tracker.py and ffmpeg_manager.py in the same directory or PYTHONPATH.
    # 4. Write permissions to ffmpeg_manager.HLS_BASE_PATH.
    #
    # Note: These tests can be time-consuming as they may involve starting/stopping
    # real FFmpeg processes and waiting for I/O operations.
    # For true unit tests, ffmpeg_manager.start_master_stream and parts of stream_tracker
    # would typically be mocked.

    print("\n--- Channel Manager Integration Test Suite ---")

    # --- Test Suite Setup ---
    # Ensure Redis is available for these tests
    if not _ensure_redis_connection():
        logging.critical("CRITICAL: Redis not available. Aborting channel_manager tests.")
        exit(1)
    logging.info("Successfully connected to Redis for testing.")

    # Test data
    # A known valid, public HLS stream URL for testing successful stream startup.
    # If this URL becomes invalid, Test Case 1 may fail.
    valid_stream_url = "http://devimages.apple.com/iphone/samples/bipbop/bipbopall.m3u8"
    # An invalid URL that FFmpeg should fail to process.
    invalid_stream_url = "http://thisshouldnotexist.invalid/stream.m3u8"


    # --- Test: _construct_public_hls_url (Unit Test) ---
    # This section tests the URL construction logic in isolation.
    print("\n--- Testing Helper: _construct_public_hls_url ---")
    logging.info(f"Using FM_HLS_BASE_PATH for URL construction tests: {FM_HLS_BASE_PATH}")

    # Test case 1: Valid path within the HLS base path
    test_valid_path = os.path.join(FM_HLS_BASE_PATH, "channelxyz", "playlist.m3u8")
    constructed_url = _construct_public_hls_url(test_valid_path)
    expected_valid_url = f"http://{HLS_SERVER_HOST}:{HLS_SERVER_PORT}/channelxyz/playlist.m3u8"
    assert constructed_url == expected_valid_url, f"Valid path test: Expected {expected_valid_url}, got {constructed_url}"
    logging.info(f"PASS: Valid path test: {test_valid_path} -> {constructed_url}")

    # Test case 2: Path with normalization characters (e.g., ".")
    test_unnormalized_path = os.path.join(FM_HLS_BASE_PATH, "channelxyz", ".", "playlist.m3u8")
    constructed_unnormalized_url = _construct_public_hls_url(test_unnormalized_path)
    assert constructed_unnormalized_url == expected_valid_url, f"Unnormalized path test: Expected {expected_valid_url}, got {constructed_unnormalized_url}"
    logging.info(f"PASS: Unnormalized path test: {test_unnormalized_path} -> {constructed_unnormalized_url}")

    # Test case 3: Path outside the HLS base path (should fail)
    test_outside_path = "/etc/passwd"
    constructed_outside_url = _construct_public_hls_url(test_outside_path)
    assert constructed_outside_url is None, f"Path outside base test: Expected None, got {constructed_outside_url}"
    logging.info(f"PASS: Path outside base test: {test_outside_path} -> None")

    # Test case 4: Path above the HLS base path (should fail)
    test_above_path = os.path.join(os.path.dirname(os.path.abspath(FM_HLS_BASE_PATH)), "somefile.m3u8")
    constructed_above_url = _construct_public_hls_url(test_above_path)
    assert constructed_above_url is None, f"Path above base test: Expected None, got {constructed_above_url}"
    logging.info(f"PASS: Path above base test: {test_above_path} -> None")
    
    # Test case 5: Path with ".." that normalizes to still be under base (should pass)
    test_tricky_path = os.path.join(FM_HLS_BASE_PATH, "channel1", "..", "channel1", "playlist.m3u8")
    # This normalizes to /mnt/hls_streams/channel1/playlist.m3u8 (or similar based on FM_HLS_BASE_PATH)
    constructed_tricky_url = _construct_public_hls_url(test_tricky_path)
    expected_tricky_url = f"http://{HLS_SERVER_HOST}:{HLS_SERVER_PORT}/channel1/playlist.m3u8"
    assert constructed_tricky_url == expected_tricky_url, f"Tricky path test: Expected {expected_tricky_url}, got {constructed_tricky_url}"
    logging.info(f"PASS: Tricky path (normalized under base) test: {test_tricky_path} -> {constructed_tricky_url}")
    print("--- _construct_public_hls_url tests complete ---")


    # --- Test Suite: get_hls_playlist_for_channel (Integration Tests) ---
    print("\n--- Testing Main Function: get_hls_playlist_for_channel ---")
    # Required imports for these tests
    import time
    import shutil
    import subprocess # For killing processes in cleanup

    def _cleanup_stream_resources_for_test(channel_id_to_clean, base_hls_path):
        """Comprehensive cleanup helper for integration tests."""
        logging.info(f"Test Cleanup: Attempting to clean up resources for channel_id: {channel_id_to_clean}...")
        stream_info = stream_tracker.get_stream(channel_id_to_clean)
        
        # Stop FFmpeg process if PID is found
        if stream_info and 'pid' in stream_info:
            pid_to_kill = int(stream_info['pid'])
            logging.info(f"Test Cleanup: Found PID {pid_to_kill} for {channel_id_to_clean}. Attempting to terminate FFmpeg process.")
            try:
                subprocess.run(["kill", str(pid_to_kill)], check=False, timeout=3)
                time.sleep(1) # Allow graceful shutdown
                subprocess.run(["kill", "-9", str(pid_to_kill)], check=False, timeout=3) # Force kill
                logging.info(f"Test Cleanup: Kill signals sent for FFmpeg PID {pid_to_kill}.")
            except ProcessLookupError:
                logging.info(f"Test Cleanup: FFmpeg process {pid_to_kill} not found (already stopped).")
            except Exception as e_kill:
                logging.error(f"Test Cleanup: Error killing FFmpeg process {pid_to_kill}: {e_kill}")
        
        # Remove stream from Redis tracker
        if stream_tracker.remove_stream(channel_id_to_clean):
             logging.info(f"Test Cleanup: Removed stream {channel_id_to_clean} from tracker.")
        else:
            logging.info(f"Test Cleanup: Stream {channel_id_to_clean} not found in tracker or remove failed.")
        
        # Delete HLS directory from filesystem
        hls_dir_path = os.path.join(base_hls_path, channel_id_to_clean)
        if os.path.exists(hls_dir_path):
            try:
                shutil.rmtree(hls_dir_path)
                logging.info(f"Test Cleanup: Removed HLS directory: {hls_dir_path}")
            except Exception as e_rmdir:
                logging.error(f"Test Cleanup: Error removing HLS directory {hls_dir_path}: {e_rmdir}")
        else:
            logging.info(f"Test Cleanup: HLS directory {hls_dir_path} not found, no removal needed.")
        logging.info(f"Test Cleanup: Resource cleanup for {channel_id_to_clean} finished.")

    # Test Case 1: Requesting a new, valid stream
    # Objective: Verify that a stream is started for a new URL, added to tracker, and a public URL is returned.
    logging.info("\n--- Test Case 1: New Valid Stream Request ---")
    channel_id_valid = stream_tracker.generate_channel_id(valid_stream_url)
    _cleanup_stream_resources_for_test(channel_id_valid, FM_HLS_BASE_PATH) # Pre-cleanup

    logging.info(f"Requesting stream for URL: {valid_stream_url} (Channel ID: {channel_id_valid})")
    public_playlist_url = get_hls_playlist_for_channel(valid_stream_url)
    
    assert public_playlist_url is not None, "FAIL: get_hls_playlist_for_channel returned None for a valid new URL."
    logging.info(f"PASS: Received public playlist URL for new stream: {public_playlist_url}")
    
    # Verify stream is in tracker and FFmpeg process exists (implicitly tested by ffmpeg_manager's test, here we check tracker)
    stream_info_case1 = stream_tracker.get_stream(channel_id_valid)
    assert stream_info_case1 is not None, f"FAIL: Stream {channel_id_valid} not found in tracker after starting."
    assert 'pid' in stream_info_case1, "FAIL: PID missing from stream info in tracker."
    assert 'hls_playlist_path' in stream_info_case1, "FAIL: HLS playlist path missing from stream info."
    logging.info(f"PASS: Stream {channel_id_valid} (PID: {stream_info_case1['pid']}) correctly added to tracker.")
    # Note: A full test would check if FFmpeg PID is actually running. ffmpeg_manager's own test covers this.
    # For brevity here, we trust ffmpeg_manager.start_master_stream's success implies a running process.
    # A short delay might be needed if we were to check for actual playlist file creation immediately.
    time.sleep(1) # Small delay for FFmpeg to initialize, if checking files.

    # Test Case 2: Requesting the same stream again (cache hit)
    # Objective: Verify that a request for an already active stream returns the existing URL and updates activity.
    logging.info("\n--- Test Case 2: Cached Stream Request ---")
    if stream_info_case1: # Proceed only if stream was started
        last_activity_before_cache_hit = float(stream_info_case1['last_activity_timestamp'])
        time.sleep(1.1) # Ensure timestamp difference
        
        cached_playlist_url = get_hls_playlist_for_channel(valid_stream_url)
        assert cached_playlist_url == public_playlist_url, \
            f"FAIL: Cached URL ({cached_playlist_url}) differs from initial URL ({public_playlist_url})."
        logging.info(f"PASS: Received same public playlist URL for cached stream: {cached_playlist_url}")
        
        stream_info_after_cache_hit = stream_tracker.get_stream(channel_id_valid)
        assert stream_info_after_cache_hit is not None, "FAIL: Stream info lost after cache hit."
        last_activity_after_cache_hit = float(stream_info_after_cache_hit['last_activity_timestamp'])
        assert last_activity_after_cache_hit > last_activity_before_cache_hit, \
            "FAIL: Last activity timestamp did not update after cache hit."
        logging.info(f"PASS: Last activity timestamp updated from {last_activity_before_cache_hit} to {last_activity_after_cache_hit}.")
    else:
        logging.warning("SKIP: Test Case 2 (Cached Stream Request) skipped as stream was not started in Test Case 1.")
        
    # Test Case 3: Requesting an invalid stream URL
    # Objective: Verify that a request for an invalid URL fails gracefully and does not create a stream entry.
    logging.info("\n--- Test Case 3: Invalid Stream URL Request ---")
    channel_id_invalid = stream_tracker.generate_channel_id(invalid_stream_url)
    _cleanup_stream_resources_for_test(channel_id_invalid, FM_HLS_BASE_PATH) # Pre-cleanup

    logging.info(f"Requesting stream for invalid URL: {invalid_stream_url} (Channel ID: {channel_id_invalid})")
    failed_url_response = get_hls_playlist_for_channel(invalid_stream_url)
    assert failed_url_response is None, \
        f"FAIL: Expected None for invalid URL, but got {failed_url_response}."
    logging.info(f"PASS: Correctly received None for invalid stream URL.")
    
    assert stream_tracker.get_stream(channel_id_invalid) is None, \
        f"FAIL: Stream {channel_id_invalid} was added to tracker despite invalid URL."
    logging.info(f"PASS: Stream {channel_id_invalid} was not added to tracker for invalid URL.")

    # Final cleanup for the valid stream started in Test Case 1
    logging.info("\n--- Final Test Cleanup ---")
    _cleanup_stream_resources_for_test(channel_id_valid, FM_HLS_BASE_PATH)
    
    logging.info("--- Channel Manager Integration Test Suite Complete ---")
