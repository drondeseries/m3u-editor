"""
A simple HTTP server for serving HLS (HTTP Live Streaming) files (.m3u8, .ts)
from a specified base directory.
"""
import os
import logging
import shutil # Added for shutil.copyfileobj
from http.server import HTTPServer, SimpleHTTPRequestHandler
from socketserver import ThreadingMixIn # For handling multiple requests

# --- Configuration ---
HLS_BASE_PATH = os.path.abspath("/mnt/hls_streams") # Absolute path to HLS content
SERVER_HOST = "0.0.0.0"
SERVER_PORT = 8080
# --- End Configuration ---

# Configure basic logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

class HLSRequestHandler(SimpleHTTPRequestHandler):
    """
    Custom request handler for serving HLS files.
    Ensures files are served from within HLS_BASE_PATH and with correct MIME types.
    """

    def _log_request_details(self, response_code):
        """Helper to log request details."""
        logging.info(f"{self.client_address[0]}:{self.client_address[1]} - "
                     f"\"{self.requestline}\" {response_code}")

    def do_GET(self):
        """Handles GET requests for HLS files."""
        # Sanitize and construct the full file path
        # self.path usually starts with '/', e.g., /channel1/playlist.m3u8
        # os.path.join will handle this correctly if the second part starts with /
        # but it's cleaner to strip it.
        requested_path_segment = self.path.lstrip('/')
        
        # Prevent directory traversal by ensuring requested_path_segment is relative
        if ".." in requested_path_segment or requested_path_segment.startswith('/'):
            self.send_error(403, "Forbidden - Invalid path")
            self._log_request_details(403)
            return

        file_path = os.path.join(HLS_BASE_PATH, requested_path_segment)
        abs_file_path = os.path.abspath(file_path)

        # Security Check: Ensure the resolved path is still within HLS_BASE_PATH
        if not abs_file_path.startswith(HLS_BASE_PATH):
            self.send_error(403, "Forbidden - Access denied")
            self._log_request_details(403)
            return

        # Check if the file exists and is a file (not a directory)
        if not os.path.exists(abs_file_path) or not os.path.isfile(abs_file_path):
            self.send_error(404, "File Not Found")
            self._log_request_details(404)
            return

        # Determine MIME type based on file extension
        mime_type = None
        if abs_file_path.endswith(".m3u8"):
            mime_type = "application/vnd.apple.mpegurl"
        elif abs_file_path.endswith(".ts"):
            mime_type = "video/mp2t"
        else:
            # For this HLS server, we only explicitly serve .m3u8 and .ts
            # Other file types are denied.
            self.send_error(403, "Forbidden - File type not supported")
            self._log_request_details(403)
            return

        # Send headers and file content
        try:
            with open(abs_file_path, 'rb') as f:
                fs = os.fstat(f.fileno()) # Get stats while file is open
                
                # Send response and headers
                self.send_response(200)
                self.send_header("Content-type", mime_type) # mime_type already determined
                self.send_header("Content-Length", str(fs.st_size)) # Use fs.st_size
                self.send_header("Last-Modified", self.date_time_string(fs.st_mtime))
                self.end_headers()
                
                # Send file content
                shutil.copyfileobj(f, self.wfile) # Using shutil.copyfileobj
            self._log_request_details(200) # Log success
            
        except FileNotFoundError:
            # This specific exception might be redundant if os.path.exists was checked earlier,
            # but good for robustness if file disappears between check and open.
            logging.warning(f"File not found: {self.path} (resolved to {abs_file_path})")
            self.send_error(404, "File Not Found")
            self._log_request_details(404)
        except PermissionError:
            logging.warning(f"Permission denied for: {self.path} (resolved to {abs_file_path})")
            self.send_error(403, "Forbidden")
            self._log_request_details(403)
        except ConnectionAbortedError:
            logging.warning(f"Connection aborted by client while serving {abs_file_path}: {self.client_address[0]}")
            # Cannot send error to client as connection is gone.
        except OSError as e:
            # Catching other OS-level errors, including potential Bad File Descriptor
            # if 'with' statement didn't fully solve an edge case.
            logging.error(f"OSError serving file {self.path} (resolved to {abs_file_path}): {e}")
            # Check if headers were already sent before trying to send an error response.
            # SimpleHTTPRequestHandler doesn't have a public 'headers_sent' attribute.
            # A common pattern is to assume if an OSError happens during/after send_response,
            # it's too late to send a clean error. If it happens before, send_error is okay.
            # For simplicity here, we'll attempt send_error. It might fail if part of headers sent.
            self.send_error(500, "Internal Server Error - OSError")
            self._log_request_details(500)
        except Exception as e:
            logging.error(f"Unexpected error serving file {self.path} (resolved to {abs_file_path}): {e}", exc_info=True)
            # Removing 'if not self.headers_sent:' check
            self.send_error(500, "Internal Server Error - Unexpected")
            self._log_request_details(500)

    def list_directory(self, path):
        """Disable directory listing."""
        self.send_error(403, "Forbidden - Directory listing not allowed")
        self._log_request_details(403)
        return None

    # Override log_message to prevent default http.server logging if desired,
    # as we have custom logging in _log_request_details.
    # If you want both, you can remove this override or call super().log_message()
    def log_message(self, format, *args):
        """Suppress default SimpleHTTPRequestHandler logging unless debugging."""
        # logging.debug(f"SimpleHTTPRequestHandler log: {format % args}")
        pass

class ThreadingHLSServer(ThreadingMixIn, HTTPServer):
    """Handle requests in a separate thread."""
    daemon_threads = True # Allow main thread to exit even if worker threads are active


def run_server():
    """Starts the HLS HTTP server."""
    if not os.path.exists(HLS_BASE_PATH):
        logging.warning(f"HLS_BASE_PATH '{HLS_BASE_PATH}' does not exist. Creating it.")
        try:
            os.makedirs(HLS_BASE_PATH)
        except OSError as e:
            logging.error(f"Could not create HLS_BASE_PATH '{HLS_BASE_PATH}': {e}")
            return
    
    server_address = (SERVER_HOST, SERVER_PORT)
    httpd = None
    logging.info(f"HLS Server attempting to start on {SERVER_HOST}:{SERVER_PORT}, serving from {HLS_BASE_PATH}")
    try:
        httpd = ThreadingHLSServer(server_address, HLSRequestHandler)
        logging.info(f"HLS Server started successfully. Listening on http://{SERVER_HOST}:{SERVER_PORT}")
        logging.info(f"Serving HLS content from base directory: {HLS_BASE_PATH}")
        logging.info("Use Ctrl+C to stop the server.")
        httpd.serve_forever()
    except KeyboardInterrupt:
        logging.info("HLS Server shutting down (KeyboardInterrupt received)...")
    except OSError as e:
        logging.critical(f"Could not start HLS Server on {SERVER_HOST}:{SERVER_PORT}: {e}")
        logging.critical("Common causes: Port already in use, insufficient permissions, or invalid host address.")
    except Exception as e_global:
        logging.critical(f"An unexpected error occurred while trying to start or run the HLS server: {e_global}", exc_info=True)
    finally:
        if httpd:
            logging.info("HLS Server shutdown sequence initiated.")
            httpd.shutdown() # Cleanly shut down the server
            httpd.server_close() # Close the server socket
            logging.info("HLS Server stopped completely.")
        else:
            logging.info("HLS Server did not start or was already stopped.")

if __name__ == "__main__":
    print("--- HLS Server ---")
    print(f"Base HLS directory configured: {HLS_BASE_PATH}")
    print(f"Server host configured: {SERVER_HOST}")
    print(f"Server port configured: {SERVER_PORT}")
    print("Starting server...")
    run_server()
    print("--- HLS Server Terminated ---")
