"""
A Python module for tracking active FFmpeg master streams using Redis.
"""
import redis
import time
import hashlib

# Default Redis connection parameters
REDIS_HOST = 'localhost'
REDIS_PORT = 6379
REDIS_DB = 0

# Global Redis connection instance
redis_client = None

def _get_redis_connection():
    """
    Establishes and returns a Redis connection.
    Handles connection errors gracefully.
    """
    global redis_client
    if redis_client is None:
        try:
            redis_client = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB, decode_responses=True)
            redis_client.ping()
            print("Successfully connected to Redis.")
        except redis.exceptions.ConnectionError as e:
            print(f"Error connecting to Redis: {e}")
            # In a real application, you might want to raise an exception
            # or implement a retry mechanism.
            return None
    return redis_client

def generate_channel_id(url: str) -> str:
    """
    Generates a consistent channel_id from a stream URL using SHA256 hash.

    Args:
        url: The URL of the stream.

    Returns:
        A SHA256 hash of the URL, prefixed with "stream:".
    """
    if not url:
        raise ValueError("URL cannot be empty")
    hashed_url = hashlib.sha256(url.encode('utf-8')).hexdigest()
    return f"stream:{hashed_url}"

def add_stream(channel_id: str, original_url: str, pid: int, hls_playlist_path: str) -> bool:
    """
    Adds a new stream to Redis.

    Sets `last_activity_timestamp` and `created_timestamp` to the current time.

    Args:
        channel_id: The unique identifier for the stream.
        original_url: The full original URL of the source stream.
        pid: The Process ID (PID) of the master FFmpeg process.
        hls_playlist_path: The absolute file system path to the .m3u8 playlist.

    Returns:
        True on success, False otherwise (e.g., if the stream already exists or Redis error).
    """
    r = _get_redis_connection()
    if not r:
        return False
    if r.exists(channel_id):
        print(f"Stream with channel_id '{channel_id}' already exists.")
        return False

    current_time = time.time()
    stream_data = {
        'original_url': original_url,
        'pid': pid,
        'hls_playlist_path': hls_playlist_path,
        'last_activity_timestamp': current_time,
        'created_timestamp': current_time,
    }
    try:
        r.hmset(channel_id, stream_data)
        return True
    except redis.exceptions.RedisError as e:
        print(f"Redis error adding stream '{channel_id}': {e}")
        return False

def get_stream(channel_id: str) -> dict | None:
    """
    Retrieves stream details for the given channel_id.

    Args:
        channel_id: The unique identifier for the stream.

    Returns:
        A dictionary with the stream fields if found, None otherwise.
    """
    r = _get_redis_connection()
    if not r:
        return None
    try:
        stream_data = r.hgetall(channel_id)
        if not stream_data:
            return None
        # Convert relevant fields to appropriate types
        stream_data['pid'] = int(stream_data['pid'])
        stream_data['last_activity_timestamp'] = float(stream_data['last_activity_timestamp'])
        stream_data['created_timestamp'] = float(stream_data['created_timestamp'])
        return stream_data
    except redis.exceptions.RedisError as e:
        print(f"Redis error getting stream '{channel_id}': {e}")
        return None
    except ValueError as e:
        print(f"Data type conversion error for stream '{channel_id}': {e}")
        # Potentially corrupted data in Redis, consider removing or logging more severely
        return None

def update_stream_activity(channel_id: str) -> bool:
    """
    Updates the `last_activity_timestamp` for the given channel_id to the current time.

    Args:
        channel_id: The unique identifier for the stream.

    Returns:
        True if the stream exists and was updated, False otherwise.
    """
    r = _get_redis_connection()
    if not r:
        return False
    if not r.exists(channel_id):
        print(f"Stream with channel_id '{channel_id}' not found for update.")
        return False

    current_time = time.time()
    try:
        r.hset(channel_id, 'last_activity_timestamp', current_time)
        return True
    except redis.exceptions.RedisError as e:
        print(f"Redis error updating stream activity for '{channel_id}': {e}")
        return False

def remove_stream(channel_id: str) -> bool:
    """
    Removes the stream entry for the given channel_id from Redis.

    Args:
        channel_id: The unique identifier for the stream.

    Returns:
        True if the stream existed and was removed, False otherwise.
    """
    r = _get_redis_connection()
    if not r:
        return False
    try:
        # The `delete` command returns the number of keys removed.
        # If the key exists, it will return 1, otherwise 0.
        result = r.delete(channel_id)
        return result == 1
    except redis.exceptions.RedisError as e:
        print(f"Redis error removing stream '{channel_id}': {e}")
        return False

def list_streams() -> list[dict]:
    """
    Returns a list of all active stream details.

    Each item in the list is a dictionary similar to what get_stream returns,
    including the 'channel_id'.

    Returns:
        A list of dictionaries, or an empty list if no streams are found or on Redis error.
    """
    r = _get_redis_connection()
    if not r:
        return []

    stream_keys = []
    try:
        # Use SCAN to avoid blocking Redis on large datasets
        # Adjust count as needed, or use default
        cursor = '0'
        while cursor != 0:
            cursor, keys = r.scan(cursor=cursor, match="stream:*", count=100)
            stream_keys.extend(keys)
    except redis.exceptions.RedisError as e:
        print(f"Redis error scanning for stream keys: {e}")
        return []

    all_streams = []
    for key in stream_keys:
        stream_data = get_stream(key) # Use existing get_stream to fetch and parse
        if stream_data:
            stream_data['channel_id'] = key # Add channel_id to the dictionary
            all_streams.append(stream_data)
    return all_streams

if __name__ == '__main__':
    # Example Usage / Basic Test Suite
    # This block demonstrates the functionality of each public function in the module.
    # It requires a running Redis server.
    
    print("\n--- Stream Tracker Module Test Suite ---")
    r = _get_redis_connection()
    if not r:
        print("CRITICAL: Failed to connect to Redis. Aborting stream_tracker tests.")
        exit(1) # Exit if Redis is not available, as tests will fail.
    
    print("Successfully connected to Redis for testing.")

    # Test Data
    url1 = "http://example.com/live/stream1.m3u8"
    url2 = "http://example.com/live/stream2.m3u8"
    pid1, pid2 = 12345, 67890
    hls_path1 = "/mnt/hls/stream1/playlist.m3u8"
    hls_path2 = "/mnt/hls/stream2/master.m3u8"

    # --- Test: generate_channel_id ---
    print("\n1. Testing generate_channel_id...")
    url1 = "http://example.com/live/stream1.m3u8"
    url2 = "http://example.com/live/stream2.m3u8"
    channel_id1 = generate_channel_id(url1)
    channel_id2 = generate_channel_id(url2)
    assert "stream:" in channel_id1 and len(channel_id1) > 7, "Channel ID1 format error"
    assert "stream:" in channel_id2 and len(channel_id2) > 7, "Channel ID2 format error"
    print(f"Generated channel_id for '{url1}': {channel_id1}")
    print(f"Generated channel_id for '{url2}': {channel_id2}")
    # Test empty URL for generate_channel_id
    try:
        generate_channel_id("")
        print("FAIL: generate_channel_id did not raise ValueError for empty URL.")
    except ValueError:
        print("PASS: generate_channel_id correctly raised ValueError for empty URL.")


    # Initial cleanup of any pre-existing test keys from previous runs
    print("\nPerforming initial cleanup of test keys in Redis...")
    keys_to_delete = [channel_id1, channel_id2, "stream:doesnotexist"]
    deleted_count = r.delete(*keys_to_delete) # Unpack list for delete
    print(f"Cleaned {deleted_count} pre-existing test key(s).")


    # --- Test: add_stream ---
    print("\n2. Testing add_stream...")
    assert add_stream(channel_id1, url1, pid1, hls_path1) is True, "Failed to add stream 1"
    print(f"Stream {channel_id1} added successfully.")
    
    # Try adding the same stream again (should fail as it already exists)
    assert add_stream(channel_id1, url1, pid1, hls_path1) is False, "Should have failed to add existing stream 1 again"
    print(f"Correctly failed to add existing stream {channel_id1} again.")

    assert add_stream(channel_id2, url2, pid2, hls_path2) is True, "Failed to add stream 2"
    print(f"Stream {channel_id2} added successfully.")


    # --- Test: get_stream ---
    print("\n3. Testing get_stream...")
    stream_info1 = get_stream(channel_id1)
    assert stream_info1 is not None, f"Stream {channel_id1} not found after adding."
    assert stream_info1['original_url'] == url1, "Stream 1 URL mismatch"
    assert stream_info1['pid'] == pid1, "Stream 1 PID mismatch"
    assert stream_info1['hls_playlist_path'] == hls_path1, "Stream 1 HLS path mismatch"
    assert 'last_activity_timestamp' in stream_info1, "Missing last_activity_timestamp"
    assert 'created_timestamp' in stream_info1, "Missing created_timestamp"
    print(f"Stream {channel_id1} info retrieved: {stream_info1}")

    non_existent_channel_id = "stream:doesnotexist" # Ensure this key is cleaned up
    assert get_stream(non_existent_channel_id) is None, "Found a non-existent stream"
    print(f"Correctly did not find stream {non_existent_channel_id}.")


    # --- Test: list_streams ---
    print("\n4. Testing list_streams...")
    all_streams = list_streams()
    assert len(all_streams) == 2, f"Expected 2 streams, found {len(all_streams)}"
    print(f"Found {len(all_streams)} streams:")
    found_channel_ids = {s['channel_id'] for s in all_streams}
    assert channel_id1 in found_channel_ids, "Stream 1 not found in list_streams"
    assert channel_id2 in found_channel_ids, "Stream 2 not found in list_streams"
    for stream in all_streams:
        print(f"  - {stream['channel_id']}: PID {stream['pid']}, URL {stream['original_url']}")


    # --- Test: update_stream_activity ---
    print("\n5. Testing update_stream_activity...")
    stream1_data_before_update = get_stream(channel_id1)
    assert stream1_data_before_update is not None, "Stream 1 not found before activity update"
    old_activity_ts = stream1_data_before_update['last_activity_timestamp']
    print(f"Old activity timestamp for {channel_id1}: {old_activity_ts}")
    
    time.sleep(1.1) # Ensure timestamp has a chance to change significantly
    
    assert update_stream_activity(channel_id1) is True, f"Failed to update stream {channel_id1} activity"
    print(f"Stream {channel_id1} activity updated.")
    
    stream1_data_after_update = get_stream(channel_id1)
    assert stream1_data_after_update is not None, "Stream 1 not found after activity update"
    new_activity_ts = stream1_data_after_update['last_activity_timestamp']
    print(f"New activity timestamp for {channel_id1}: {new_activity_ts}")
    assert new_activity_ts > old_activity_ts, "Timestamp update error or no change."
    print("Timestamp updated correctly.")

    assert update_stream_activity(non_existent_channel_id) is False, "Should have failed to update non-existent stream"
    print(f"Correctly failed to update non-existent stream {non_existent_channel_id}.")


    # --- Test: remove_stream ---
    print("\n6. Testing remove_stream...")
    assert remove_stream(channel_id1) is True, f"Failed to remove stream {channel_id1}"
    print(f"Stream {channel_id1} removed successfully.")

    assert remove_stream(channel_id1) is False, "Should have failed to remove already removed stream"
    print(f"Correctly failed to remove already removed stream {channel_id1}.")

    # Verify it's gone using get_stream
    assert get_stream(channel_id1) is None, f"Stream {channel_id1} found after removal."
    print(f"Stream {channel_id1} is no longer found after removal.")


    # --- Test: list_streams again after removal ---
    print("\n7. Testing list_streams after removal...")
    all_streams_after_removal = list_streams()
    assert len(all_streams_after_removal) == 1, f"Expected 1 stream after removal, found {len(all_streams_after_removal)}"
    if all_streams_after_removal:
        print(f"Found {len(all_streams_after_removal)} stream(s):")
        assert all_streams_after_removal[0]['channel_id'] == channel_id2, "Remaining stream is not channel_id2"
        print(f"  - {all_streams_after_removal[0]['channel_id']}")
    else: # Should not happen based on assert above
        print("No streams found (unexpected).")


    # --- Final Cleanup of remaining test data ---
    print("\n8. Final cleanup of test data...")
    # channel_id1 is already removed. Remove channel_id2.
    # non_existent_channel_id was never added, so no need to remove.
    if r.exists(channel_id2):
        if remove_stream(channel_id2): # Use our function for consistency
            print(f"Cleaned up stream {channel_id2} using remove_stream.")
        else:
            # Fallback to direct delete if remove_stream fails for some reason
            r.delete(channel_id2)
            print(f"Cleaned up stream {channel_id2} using direct Redis delete (fallback).")
    else:
        print(f"Stream {channel_id2} was already cleaned up or not found.")

    # Verify all test keys are gone
    final_check_keys = r.keys("stream:*") # Be careful with keys * in production
    test_related_keys_remaining = [k for k in final_check_keys if k in (channel_id1, channel_id2, non_existent_channel_id)]
    if not test_related_keys_remaining:
        print("All test-specific keys successfully cleaned up from Redis.")
    else:
        print(f"WARNING: Some test keys may remain in Redis: {test_related_keys_remaining}")

    print("\n--- Stream Tracker Module Test Suite Complete ---")
