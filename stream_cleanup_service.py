"""
A service that periodically checks for inactive HLS streams and cleans them up.
It terminates the FFmpeg process, deletes HLS files, and removes the stream from Redis.
"""

import os
import signal
import time
import shutil
import logging
import stream_tracker
import ffmpeg_manager # To get HLS_BASE_PATH

# --- Configuration ---
INACTIVITY_TIMEOUT_SECONDS = 300  # 5 minutes
CLEANUP_INTERVAL_SECONDS = 60   # 1 minute
FM_HLS_BASE_PATH = ffmpeg_manager.HLS_BASE_PATH # Base path for HLS streams
# --- End Configuration ---

# Configure basic logging
logging.basicConfig(level=logging.INFO,
                    format='%(asctime)s - %(levelname)s - %(module)s - %(message)s',
                    handlers=[logging.StreamHandler()]) # Log to console

def _ensure_redis_connection():
    """
    Ensures that the Redis connection in stream_tracker is available.
    """
    if not stream_tracker._get_redis_connection():
        logging.error("Failed to establish Redis connection via stream_tracker.")
        return False
    return True

def terminate_process(pid: int, channel_id: str):
    """
    Terminates a process first with SIGTERM, then with SIGKILL if necessary.
    """
    logging.info(f"[{channel_id}] Attempting to terminate FFmpeg process PID: {pid}...")
    try:
        os.kill(pid, signal.SIGTERM)
        logging.info(f"[{channel_id}] Sent SIGTERM to PID: {pid}.")
        # Wait a bit for graceful shutdown
        time.sleep(2) # Give it a couple of seconds

        # Check if process is still alive
        os.kill(pid, 0) # This will raise ProcessLookupError if not alive, or do nothing if alive
        
        # If we reach here, process is still alive, send SIGKILL
        logging.warning(f"[{channel_id}] Process PID: {pid} still alive after SIGTERM. Sending SIGKILL.")
        os.kill(pid, signal.SIGKILL)
        logging.info(f"[{channel_id}] Sent SIGKILL to PID: {pid}.")
        time.sleep(0.5) # Brief pause after SIGKILL

    except ProcessLookupError:
        logging.info(f"[{channel_id}] Process PID: {pid} was not found (already terminated or never existed).")
    except PermissionError:
        logging.error(f"[{channel_id}] Permission denied to terminate PID: {pid}. Check script privileges.")
    except Exception as e:
        logging.error(f"[{channel_id}] Unexpected error terminating PID: {pid}: {e}")


def cleanup_inactive_streams():
    """
    Main logic for a single cleanup cycle.
    Fetches streams, checks inactivity, and cleans up if necessary.
    """
    logging.info("Starting cleanup check cycle...")

    if not _ensure_redis_connection():
        logging.error("Skipping cleanup cycle due to Redis connection failure.")
        return

    try:
        active_streams = stream_tracker.list_streams()
    except Exception as e: # Catching a broad exception if list_streams itself fails (e.g., Redis down)
        logging.error(f"Error fetching list of active streams from stream_tracker: {e}")
        active_streams = [] # Ensure it's an empty list to avoid further errors

    if not active_streams:
        logging.info("No active streams found to check.")
        logging.info("Cleanup check cycle finished.")
        return

    logging.info(f"Found {len(active_streams)} active streams to check.")
    cleaned_count = 0

    for stream in active_streams:
        channel_id = stream.get('channel_id')
        pid_str = stream.get('pid')
        hls_playlist_path = stream.get('hls_playlist_path') # Used for confirmation, dir derived from channel_id
        last_activity_timestamp_str = stream.get('last_activity_timestamp')

        if not all([channel_id, pid_str, hls_playlist_path, last_activity_timestamp_str]):
            logging.warning(f"Skipping stream due to missing data: {stream}")
            continue

        try:
            pid = int(pid_str)
            last_activity_timestamp = float(last_activity_timestamp_str)
        except ValueError as e:
            logging.warning(f"[{channel_id}] Error converting PID or timestamp for stream: {e}. Skipping.")
            continue

        # --- New: Process Existence Check ---
        process_exists = False
        try:
            os.kill(pid, 0)
            process_exists = True
            logging.debug(f"[{channel_id}] PID {pid} is active.")
        except ProcessLookupError:
            logging.warning(f"[{channel_id}] PID {pid} not found (crashed or externally killed). Marking for cleanup.")
            process_exists = False
        except PermissionError:
            # This case is tricky. The process exists, but we don't own it.
            # This shouldn't happen if FFmpeg processes are started by the same user/system.
            logging.error(f"[{channel_id}] Permission error checking PID {pid}. Assuming it's running, but cleanup service might not be able to terminate it if inactive.")
            process_exists = True # Treat as if running to proceed to inactivity check, but termination might fail.
        except Exception as e:
            logging.error(f"[{channel_id}] Unexpected error checking PID {pid}: {e}. Assuming running for safety.")
            process_exists = True # Treat as if running

        if not process_exists:
            # Crashed/Orphaned process scenario
            logging.info(f"[{channel_id}] Cleaning up crashed/orphaned FFmpeg process (PID {pid}).")
            # terminate_process(pid, channel_id) # Call to log ProcessLookupError consistently, no actual kill needed here.
                                                # Or simply skip if we know it's gone.
                                                # Let's call it for consistent logging of "process not found".
            terminate_process(pid, channel_id) # This will log "Process PID X was not found"

            # Delete HLS Directory (same logic as inactivity cleanup)
            stream_hls_directory = os.path.join(FM_HLS_BASE_PATH, channel_id)
            abs_stream_hls_dir = os.path.abspath(stream_hls_directory)
            abs_fm_hls_base_path = os.path.abspath(FM_HLS_BASE_PATH)

            if not abs_stream_hls_dir.startswith(abs_fm_hls_base_path) or \
               abs_stream_hls_dir == abs_fm_hls_base_path:
                logging.error(f"[{channel_id}] (Crashed Stream) Calculated HLS directory '{abs_stream_hls_dir}' is not safely within "
                              f"'{abs_fm_hls_base_path}' or is the base path itself. Skipping deletion.")
            elif os.path.exists(stream_hls_directory):
                try:
                    shutil.rmtree(stream_hls_directory)
                    logging.info(f"[{channel_id}] (Crashed Stream) Successfully deleted HLS directory: {stream_hls_directory}")
                except OSError as e:
                    logging.error(f"[{channel_id}] (Crashed Stream) Error deleting HLS directory {stream_hls_directory}: {e}")
            else:
                logging.warning(f"[{channel_id}] (Crashed Stream) HLS directory {stream_hls_directory} not found for deletion.")

            # Remove Stream from Tracker
            if stream_tracker.remove_stream(channel_id):
                logging.info(f"[{channel_id}] (Crashed Stream) Successfully removed stream from tracker.")
            else:
                logging.warning(f"[{channel_id}] (Crashed Stream) Failed to remove stream from tracker, or it was already removed.")
            cleaned_count += 1
            continue # Skip inactivity check for this stream

        # --- End of Process Existence Check ---

        # Proceed with inactivity check ONLY if process exists
        inactivity_duration = time.time() - last_activity_timestamp
        logging.debug(f"[{channel_id}] PID {pid} is active. Inactivity duration: {inactivity_duration:.2f}s / {INACTIVITY_TIMEOUT_SECONDS}s timeout.")

        if inactivity_duration > INACTIVITY_TIMEOUT_SECONDS:
            logging.info(f"[{channel_id}] Stream inactive for {inactivity_duration:.2f} seconds (PID: {pid}). Cleaning up.")
            
            # 1. Terminate FFmpeg Process
            terminate_process(pid, channel_id)

            # 2. Delete HLS Directory
            stream_hls_directory = os.path.join(FM_HLS_BASE_PATH, channel_id)
            abs_stream_hls_dir = os.path.abspath(stream_hls_directory)
            abs_fm_hls_base_path = os.path.abspath(FM_HLS_BASE_PATH)

            if not abs_stream_hls_dir.startswith(abs_fm_hls_base_path) or \
               abs_stream_hls_dir == abs_fm_hls_base_path:
                logging.error(f"[{channel_id}] (Inactive Stream) Calculated HLS directory '{abs_stream_hls_dir}' is not safely within "
                              f"'{abs_fm_hls_base_path}' or is the base path itself. Skipping deletion.")
            elif os.path.exists(stream_hls_directory):
                try:
                    shutil.rmtree(stream_hls_directory)
                    logging.info(f"[{channel_id}] (Inactive Stream) Successfully deleted HLS directory: {stream_hls_directory}")
                except OSError as e:
                    logging.error(f"[{channel_id}] (Inactive Stream) Error deleting HLS directory {stream_hls_directory}: {e}")
            else:
                logging.warning(f"[{channel_id}] (Inactive Stream) HLS directory {stream_hls_directory} not found for deletion.")

            # 3. Remove Stream from Tracker
            if stream_tracker.remove_stream(channel_id):
                logging.info(f"[{channel_id}] (Inactive Stream) Successfully removed stream from tracker.")
            else:
                logging.warning(f"[{channel_id}] (Inactive Stream) Failed to remove stream from tracker, or it was already removed.")
            cleaned_count += 1
        else:
            logging.debug(f"[{channel_id}] Stream (PID {pid}) is active. Last activity: {inactivity_duration:.2f}s ago.")
    
    if cleaned_count > 0:
        logging.info(f"Cleaned up {cleaned_count} inactive stream(s) in this cycle.")
    logging.info("Cleanup check cycle finished.")


def main_loop():
    """
    Runs the cleanup logic in an infinite loop with graceful shutdown.
    """
    logging.info("--- Stream Cleanup Service ---")
    logging.info(f"Configuration - Inactivity Timeout: {INACTIVITY_TIMEOUT_SECONDS} seconds")
    logging.info(f"Configuration - Cleanup Interval: {CLEANUP_INTERVAL_SECONDS} seconds")
    logging.info(f"Configuration - HLS Base Path: {FM_HLS_BASE_PATH}")
    logging.info("Attempting to start service...")
    
    try:
        logging.info("Stream Cleanup Service started successfully.")
        logging.info("Use Ctrl+C to stop the service.")
        while True:
            cleanup_inactive_streams()
            logging.debug(f"Next cleanup check cycle in {CLEANUP_INTERVAL_SECONDS} seconds...")
            time.sleep(CLEANUP_INTERVAL_SECONDS)
    except KeyboardInterrupt:
        logging.info("Stream Cleanup Service shutting down (KeyboardInterrupt received)...")
    except Exception as e_global:
        logging.critical(f"An unexpected critical error occurred in the Stream Cleanup Service main loop: {e_global}", exc_info=True)
    finally:
        logging.info("Stream Cleanup Service stopped completely.")


if __name__ == "__main__":
    print("--- Stream Cleanup Service ---")
    # Initial check for Redis connection. Service will not start if Redis is unavailable.
    if not _ensure_redis_connection():
        logging.critical("CRITICAL: Stream Cleanup Service cannot start - Redis connection failed at startup.")
        print("CRITICAL: Redis connection failed. Please ensure Redis is running and accessible.")
        exit(1) 
    
    print(f"Starting Stream Cleanup Service. Log messages will be directed to configured logging handlers (console by default).")
    print(f"Inactivity Timeout: {INACTIVITY_TIMEOUT_SECONDS}s | Cleanup Interval: {CLEANUP_INTERVAL_SECONDS}s")
    print("Use Ctrl+C to stop the service.")
    main_loop()
    print("--- Stream Cleanup Service Terminated ---")
