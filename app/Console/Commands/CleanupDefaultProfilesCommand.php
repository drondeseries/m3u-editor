<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlaylistProfile;
use App\Models\Playlist;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CleanupDefaultProfilesCommand extends Command
{
    protected $signature = 'app:cleanup-default-profiles-command';
    protected $description = 'Finds and cleans up outdated "Default Profile" playlist profiles and diagnoses specific profile IDs. DELETION IS ACTIVE.';

    public function handle()
    {
        Log::info('[CleanupScript] Starting diagnostic and cleanup of default playlist profiles...');
        $this->info('[CleanupScript] Starting diagnostic and cleanup of default playlist profiles...');

        $problematicIds = [
            '9f07b1a8-ae8c-4a9d-9b52-ae4a90a6306c',
            '9f0776df-dcac-4b7b-9cff-d6f0cf729b0a'
        ];

        $this->info('[CleanupScript] --- Specific Profile ID Diagnostics ---');
        Log::info('[CleanupScript] --- Specific Profile ID Diagnostics ---');

        foreach ($problematicIds as $id) {
            $profile = PlaylistProfile::find($id);
            if ($profile) {
                $playlist = $profile->playlist;
                $playlistStreams = $playlist ? ($playlist->streams ?? 1) : 'N/A (No parent playlist)';
                $logMsg = "[CleanupScript] Found Profile by ID: {$id}. Name: '{$profile->name}', Max Streams: {$profile->max_streams}, Playlist ID: {$profile->playlist_id}. Parent Playlist Streams: {$playlistStreams}";
                Log::info($logMsg);
                $this->line($logMsg);
            } else {
                // Updated message to reflect that deletion is active in this version of the script
                $logMsg = "[CleanupScript] Profile with ID: {$id} NOT FOUND. It might have been deleted in the general cleanup if it matched criteria, or it never existed.";
                Log::info($logMsg);
                $this->line($logMsg);
            }
        }

        $this->info('[CleanupScript] --- General Cleanup Logic (DELETION ENABLED) ---');
        Log::info('[CleanupScript] --- General Cleanup Logic (DELETION ENABLED) ---');

        $deletedCount = 0;
        $candidateProfiles = PlaylistProfile::where('max_streams', 1)->with('playlist')->get();

        $this->info('[CleanupScript] Found ' . $candidateProfiles->count() . ' total profiles with max_streams = 1 (before name check).');
        Log::info('[CleanupScript] Found ' . $candidateProfiles->count() . ' total profiles with max_streams = 1 (before name check).');
        
        $actualCandidates = [];
        foreach($candidateProfiles as $profile) {
            // Ensure $profile->name is not null before calling trim and Str::lower
            if ($profile->name !== null && Str::lower(trim($profile->name)) === 'default profile') {
                $actualCandidates[] = $profile;
            }
        }

        $this->info('[CleanupScript] Found ' . count($actualCandidates) . ' candidate profiles after case-insensitive name check for "Default Profile" with max_streams = 1.');
        Log::info('[CleanupScript] Found ' . count($actualCandidates) . ' candidate profiles after case-insensitive name check for "Default Profile" with max_streams = 1.');

        if (count($actualCandidates) === 0) {
            $this->info('[CleanupScript] No candidate profiles matching "Default Profile" (case-insensitive) and max_streams = 1. No cleanup action needed based on general search.');
            Log::info('[CleanupScript] No candidate profiles matching "Default Profile" (case-insensitive) and max_streams = 1. No cleanup action needed based on general search.');
        } else {
            foreach ($actualCandidates as $profile) {
                $playlist = $profile->playlist;

                if ($playlist) {
                    $playlistStreams = $playlist->streams ?? 1;
                    $logMsg = "[CleanupScript] Candidate PlaylistProfile ID: {$profile->id} (Name: '{$profile->name}', max_streams: 1). Parent Playlist ID: {$playlist->id}, Playlist DB streams: " . ($playlist->streams ?? 'NULL') . ", Effective playlist streams: {$playlistStreams}.";
                    Log::info($logMsg);
                    $this->line($logMsg);

                    if ($playlistStreams > 1) {
                        $deleteLogMsg = "[CleanupScript] DELETING PlaylistProfile ID: {$profile->id} because its max_streams is 1 and parent Playlist (ID: {$playlist->id}) has effective streams of {$playlistStreams}.";
                        Log::info($deleteLogMsg);
                        $this->info($deleteLogMsg);
                        $profile->delete(); // DELETION ENABLED
                        $deletedCount++;   // DELETION ENABLED
                    } else {
                        $this->line("[CleanupScript] Skipping PlaylistProfile ID: {$profile->id} as its parent playlist effective streams ({$playlistStreams}) is not greater than 1.");
                        Log::info("[CleanupScript] Skipping PlaylistProfile ID: {$profile->id} as its parent playlist effective streams ({$playlistStreams}) is not greater than 1.");
                    }
                } else {
                    $logMsg = "[CleanupScript] PlaylistProfile ID: {$profile->id} has no parent playlist. Skipping.";
                    Log::info($logMsg);
                    $this->line($logMsg);
                }
            }
        }
        
        $this->info("[CleanupScript] Total outdated \"Default Profile\" records deleted: " . $deletedCount);
        Log::info("[CleanupScript] Total outdated \"Default Profile\" records deleted: " . $deletedCount);
        
        // Adjusted conditional message for clarity when deletions are active
        if ($deletedCount == 0 && count($actualCandidates) > 0) {
             $this->info("[CleanupScript] No profiles were deleted in this run because none of the " . count($actualCandidates) . " candidates met the specific criteria for deletion (e.g., parent playlist streams > 1).");
             Log::info("[CleanupScript] No profiles were deleted in this run because none of the " . count($actualCandidates) . " candidates met the specific criteria for deletion.");
        }


        $this->info('[CleanupScript] Script finished.');
        Log::info('[CleanupScript] Script finished.');
    }
}
