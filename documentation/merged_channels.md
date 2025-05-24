## Merged Channels Feature

The "Merged Channels" feature allows you to combine multiple stream sources into a single, more resilient channel entry in your playlists. This enhances stream reliability through an automatic failover mechanism.

### What are Merged Channels?

A Merged Channel represents a single channel in your playlist but is backed by multiple stream URLs (sources). These sources are assigned a priority.

*   **Failover Capability:** When you try to stream a Merged Channel, the system will first attempt to use the source with the highest priority (e.g., priority 0). If this stream fails (e.g., it's offline or encounters an error), the system will automatically try the next source in the priority list (e.g., priority 1), and so on, until a working stream is found or all sources have been attempted.

### How to Create a Merged Channel

You can create and manage Merged Channels within the admin panel:

1.  Navigate to the **Channels** section in the sidebar, then click on **Merged Channels**.
2.  Click "New Merged Channel" (or similar button for creation).
3.  Enter the following key information:
    *   **Name:** A descriptive name for your Merged Channel (e.g., "My Favorite News Channel - Backup").
    *   **EPG Source Channel (Optional):** You can link your Merged Channel to an existing EPG Channel entry. This will allow the Merged Channel to use the EPG data (program guide information, icons) from the selected EPG Channel when included in playlists.
    *   **Source Channels:** This is where you define the actual stream URLs and their priorities.
        *   Click "Add Source Channel".
        *   **Channel:** Select an existing regular channel from your database to act as a source. The URL of this selected channel will be used.
        *   **Priority:** Assign a numerical priority (e.g., 0 for the primary, 1 for the first backup, 2 for the second, etc.). Lower numbers have higher priority.
        *   You can add multiple source channels, each with a unique priority.
4.  Save the MergedChannel.

### How to Use Merged Channels

Once created, Merged Channels can be easily integrated into your viewing setup:

1.  Go to the **Custom** section in the sidebar, then click on **Custom Playlists**.
2.  Either create a new Custom Playlist or edit an existing one.
3.  In the form for the Custom Playlist, you will find a section or field to add channels. Merged Channels will be available for selection alongside regular channels (usually in a field like "Merged Channels" or similar multi-select dropdown).
4.  Select the Merged Channels you wish to include.
5.  Save your Custom Playlist.

When this Custom Playlist is used (e.g., in your IPTV player), any Merged Channels included will automatically utilize the failover logic. If the primary source is down, your player will be seamlessly directed to the next available source.

### Benefits

*   **Increased Stream Reliability:** Significantly reduces the chances of encountering a dead stream, as the system automatically switches to backup sources.
*   **Simplified Playlist Management:** Instead of managing multiple entries for the same channel (primary, backup 1, backup 2), you manage a single Merged Channel entry. This keeps your channel lists cleaner and easier to navigate.
*   **Flexible EPG Mapping:** You can map a Merged Channel to a specific EPG source, ensuring consistent program information even if the underlying stream source changes due to failover.
