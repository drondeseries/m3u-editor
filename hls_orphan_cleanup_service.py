"""
A service that periodically scans for and removes orphaned HLS stream directories.
An orphaned directory is one that exists in the HLS base path but is not tracked in Redis.
"""

import os
import time
import shutil
import logging
import stream_tracker
import ffmpeg_manager # To get HLS_BASE_PATH

# --- Configuration ---
FM_HLS_BASE_PATH = ffmpeg_manager.HLS_BASE_PATH  # Base path for HLS streams
MAX_ORPHAN_AGE_SECONDS = 3600  # 1 hour. Set to 0 or None to disable age check.
CLEANUP_INTERVAL_SECONDS = 3600 * 6  # Every 6 hours
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
    logging.debug("Redis connection confirmed.")
    return True

def cleanup_orphaned_directories():
    """
    Performs a single scan and cleanup cycle for orphaned HLS directories.
    """
    logging.info("Starting orphaned HLS directory scan cycle...")

    if not _ensure_redis_connection():
        logging.error("Skipping orphan cleanup cycle due to Redis connection failure.")
        return

    # 1. Fetch active stream channel_ids from stream_tracker
    active_channel_ids = set()
    try:
        active_streams = stream_tracker.list_streams()
        for stream in active_streams:
            if stream.get('channel_id'):
                active_channel_ids.add(stream['channel_id'])
        logging.info(f"Found {len(active_channel_ids)} active channel_ids in stream_tracker.")
    except Exception as e:
        logging.error(f"Error fetching list of active streams from stream_tracker: {e}. Skipping this cycle.")
        return

    # 2. List items in FM_HLS_BASE_PATH
    try:
        if not os.path.exists(FM_HLS_BASE_PATH):
            logging.warning(f"HLS base path '{FM_HLS_BASE_PATH}' does not exist. Nothing to scan.")
            return
        if not os.path.isdir(FM_HLS_BASE_PATH):
            logging.error(f"HLS base path '{FM_HLS_BASE_PATH}' is not a directory. Cannot scan.")
            return
            
        items_in_base_path = os.listdir(FM_HLS_BASE_PATH)
        logging.debug(f"Found {len(items_in_base_path)} items in '{FM_HLS_BASE_PATH}'.")
    except OSError as e:
        logging.error(f"Error listing directory '{FM_HLS_BASE_PATH}': {e}. Skipping this cycle.")
        return

    orphans_deleted_count = 0
    orphans_skipped_count = 0

    # 3. Identify and process orphans
    for item_name in items_in_base_path:
        item_path = os.path.join(FM_HLS_BASE_PATH, item_name)

        if not os.path.isdir(item_path):
            logging.debug(f"Item '{item_path}' is not a directory. Skipping.")
            continue

        # item_name is a potential channel_id (directory name)
        potential_channel_id = item_name
        
        if potential_channel_id not in active_channel_ids:
            logging.info(f"Directory '{potential_channel_id}' at '{item_path}' is not tracked in Redis (potential orphan).")

            # Age Check (if enabled)
            if MAX_ORPHAN_AGE_SECONDS and MAX_ORPHAN_AGE_SECONDS > 0:
                try:
                    mtime = os.path.getmtime(item_path)
                    age_seconds = time.time() - mtime
                    if age_seconds < MAX_ORPHAN_AGE_SECONDS:
                        logging.info(f"Orphaned directory '{item_path}' is too young ({age_seconds:.0f}s old, "
                                     f"threshold {MAX_ORPHAN_AGE_SECONDS}s). Skipping for now.")
                        orphans_skipped_count += 1
                        continue
                    else:
                        logging.info(f"Orphaned directory '{item_path}' is old enough ({age_seconds:.0f}s old) for deletion.")
                except OSError as e:
                    logging.error(f"Error getting modification time for '{item_path}': {e}. Skipping age check and attempting delete cautiously or skipping entirely.")
                    # Depending on policy, one might skip or proceed without age confirmation.
                    # For safety, let's skip if mtime fails.
                    logging.warning(f"Skipping deletion of '{item_path}' due to mtime check failure.")
                    orphans_skipped_count += 1
                    continue
            
            # Sanity check: ensure the directory to be deleted is indeed under FM_HLS_BASE_PATH
            # and is not FM_HLS_BASE_PATH itself. (Though listdir + join should be safe)
            abs_item_path = os.path.abspath(item_path)
            abs_fm_hls_base_path = os.path.abspath(FM_HLS_BASE_PATH)
            if not abs_item_path.startswith(abs_fm_hls_base_path) or \
               abs_item_path == abs_fm_hls_base_path:
                logging.error(f"Orphaned directory '{abs_item_path}' is not safely within "
                              f"'{abs_fm_hls_base_path}' or is the base path itself. CRITICAL: Skipping deletion.")
                continue # This should ideally not happen if path logic is correct.

            logging.info(f"Attempting to delete orphaned HLS directory: '{item_path}'")
            try:
                shutil.rmtree(item_path)
                logging.info(f"Successfully deleted orphaned directory: '{item_path}'")
                orphans_deleted_count += 1
            except OSError as e:
                logging.error(f"Error deleting orphaned directory '{item_path}': {e}")
        else:
            logging.debug(f"Directory '{item_path}' (channel_id: {potential_channel_id}) is tracked. Skipping.")

    if orphans_deleted_count > 0:
        logging.info(f"Deleted {orphans_deleted_count} orphaned director(y/ies) in this cycle.")
    if orphans_skipped_count > 0:
        logging.info(f"Skipped {orphans_skipped_count} young orphaned director(y/ies) in this cycle.")
    logging.info("Orphaned HLS directory scan cycle finished.")


def main_loop():
    """
    Runs the orphan cleanup logic in an infinite loop with graceful shutdown.
    """
    logging.info("--- HLS Orphan Cleanup Service ---")
    logging.info(f"Configuration - Scan Interval: {CLEANUP_INTERVAL_SECONDS} seconds")
    logging.info(f"Configuration - Max Orphan Age for Deletion: {MAX_ORPHAN_AGE_SECONDS if MAX_ORPHAN_AGE_SECONDS and MAX_ORPHAN_AGE_SECONDS > 0 else 'Immediate (Age Check Disabled)'} seconds")
    logging.info(f"Configuration - HLS Base Path: {FM_HLS_BASE_PATH}")
    logging.info("Attempting to start service...")

    try:
        logging.info("HLS Orphan Cleanup Service started successfully.")
        logging.info("Use Ctrl+C to stop the service.")
        while True:
            cleanup_orphaned_directories()
            logging.debug(f"Next orphan directory scan cycle in {CLEANUP_INTERVAL_SECONDS} seconds...")
            time.sleep(CLEANUP_INTERVAL_SECONDS)
    except KeyboardInterrupt:
        logging.info("HLS Orphan Cleanup Service shutting down (KeyboardInterrupt received)...")
    except Exception as e_global:
        logging.critical(f"An unexpected critical error occurred in the HLS Orphan Cleanup Service main loop: {e_global}", exc_info=True)
    finally:
        logging.info("HLS Orphan Cleanup Service stopped completely.")


if __name__ == "__main__":
    print("--- HLS Orphan Cleanup Service ---")
    # Initial check for Redis connection. Service will not start if Redis is unavailable.
    if not _ensure_redis_connection():
        logging.critical("CRITICAL: HLS Orphan Cleanup Service cannot start - Redis connection failed at startup.")
        print("CRITICAL: Redis connection failed. Please ensure Redis is running and accessible.")
        exit(1)
        
    print(f"Starting HLS Orphan Cleanup Service. Log messages will be directed to configured logging handlers (console by default).")
    print(f"Scan Interval: {CLEANUP_INTERVAL_SECONDS}s | Max Orphan Age: {MAX_ORPHAN_AGE_SECONDS if MAX_ORPHAN_AGE_SECONDS and MAX_ORPHAN_AGE_SECONDS > 0 else 'Immediate'}s")
    print("Use Ctrl+C to stop the service.")
    main_loop()
    print("--- HLS Orphan Cleanup Service Terminated ---")
```
