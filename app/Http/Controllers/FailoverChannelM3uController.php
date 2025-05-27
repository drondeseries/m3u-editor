<?php

namespace App\Http\Controllers;

use App\Models\FailoverChannel;
use App\Models\Channel; // Might be needed for type hinting or constants
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response; // For streaming response
use Illuminate\Support\Str; // For Str::slug
use App\Enums\ChannelLogoType;

// TODO: Import other necessary classes like PlaylistChannelId, ProxyFacade if needed later.

class FailoverChannelM3uController extends Controller
{
    // Define constants for pivot columns to avoid magic strings
    private const PIVOT_COLUMNS = [
        'order',
        'override_tvg_id',
        'override_tvg_logo',
        'override_tvg_name',
        'override_tvg_chno',
        'override_tvg_guide_stationid'
    ];

    public function generate(Request $request, FailoverChannel $failoverChannel)
    {
        // Ensure necessary models are imported at the top of the file:
        // use App\Models\Channel;
        // use App\Enums\ChannelLogoType; // If used for logo fallback logic
        // use App\Enums\PlaylistChannelId; // If used for tvg-id fallback logic (though less direct here)
        // use App\Facades\ProxyFacade; // If proxy logic is ever integrated here

        $primarySource = $failoverChannel->sources()
            ->withPivot(self::PIVOT_COLUMNS) // PIVOT_COLUMNS is already defined in the class
            ->wherePivot('order', 1)
            ->first();

        if (!$primarySource) {
            // No primary source, return empty M3U or minimal error entry
            $m3uContent = "#EXTM3U\n";
            $m3uContent .= "#EXTINF:-1 tvg-name=\"{$failoverChannel->name} (No Source)\",No Source Available\n";
            $m3uContent .= "http://error.invalid/no_source_for_failover_{$failoverChannel->id}\n";
            
            $filename = Str::slug($failoverChannel->name ?: 'failover-no-source') . '.m3u';
            return Response::make($m3uContent, 404, [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]);
        }

        // Determine TVG attributes using overrides first, then fallbacks from the Channel model
        $pivotData = $primarySource->pivot;

        // TVG Name
        $tvgName = $pivotData->override_tvg_name ?: ($primarySource->name_custom ?: $primarySource->name);

        // TVG Logo
        // For logo, if no override, use existing channel logic (which might check epgChannel, logo_type)
        $tvgLogo = $pivotData->override_tvg_logo;
        if (empty($tvgLogo)) {
            if ($primarySource->logo_type === \App\Enums\ChannelLogoType::Epg && $primarySource->epgChannel) {
                $tvgLogo = $primarySource->epgChannel->icon ?? '';
            } elseif ($primarySource->logo_type === \App\Enums\ChannelLogoType::Channel) {
                $tvgLogo = $primarySource->logo ?? '';
            }
            if (empty($tvgLogo)) {
                // Assuming a global helper 'url()' is available, or adjust as needed
                $tvgLogo = url('/placeholder.png'); 
            }
        }
        
        // TVG ID (XMLTV ID)
        $tvgId = $pivotData->override_tvg_id ?: ($primarySource->stream_id_custom ?: $primarySource->stream_id);
        // Ensure TVG ID is clean (using the app's existing config for regex)
        $tvgId = preg_replace(config('dev.tvgid.regex', '/[^A-Za-z0-9.-]/'), '', $tvgId);


        // TVG Channel Number (tvg-chno)
        // The 'channel' field on the Channel model holds its number.
        // The override 'override_tvg_chno' is a string, can be used directly.
        $tvgChno = $pivotData->override_tvg_chno ?: $primarySource->channel;
        if ($tvgChno === null || $tvgChno === '') { // If original channel number is also null/empty
            // Decide on a fallback for tvg-chno if it's critical. 
            // Could use primarySource->id or leave empty if allowed by player.
            // For now, leave potentially empty if both override and original are empty.
        }

        // Title for the M3U line (usually a display name)
        // Often same as tvg-name, but can be different. Let's use tvg-name for simplicity here.
        $extinfTitle = $tvgName;

        // Stream URL should point to the FailoverStreamController route
        // The route name is 'stream.failover'
        $streamUrl = route('stream.failover', ['failoverChannel' => $failoverChannel->id]);

        // Construct #EXTINF line
        $extInfLine = "#EXTINF:-1";
        if (!empty($tvgId)) $extInfLine .= " tvg-id=\"{$tvgId}\"";
        if (!empty($tvgName)) $extInfLine .= " tvg-name=\"{$tvgName}\"";
        if (!empty($tvgLogo)) $extInfLine .= " tvg-logo=\"{$tvgLogo}\"";
        if ($tvgChno !== null && $tvgChno !== '') $extInfLine .= " tvg-chno=\"{$tvgChno}\"";
        // Note: tvg-guide-stationid is not a standard M3U tag in EXTINF, it's for XMLTV.
        // We can add other original channel attributes if needed, e.g., group-title
        $originalGroup = $primarySource->group_internal ?: $primarySource->group;
        if (!empty($originalGroup)) $extInfLine .= " group-title=\"{$originalGroup}\"";
        // Add timeshift, catchup from primary source if they exist (no overrides for these planned)
        if ($primarySource->shift) $extInfLine .= " timeshift=\"{$primarySource->shift}\"";
        if ($primarySource->catchup) $extInfLine .= " catchup=\"{$primarySource->catchup}\"";
        if ($primarySource->catchup_source) $extInfLine .= " catchup-source=\"{$primarySource->catchup_source}\"";

        $extInfLine .= ",{$extinfTitle}";

        // Build M3U Content
        $m3uContent = "#EXTM3U\n";
        $m3uContent .= $extInfLine . "\n";
        $m3uContent .= $streamUrl . "\n";

        $filename = Str::slug($failoverChannel->name ?: 'failover-playlist') . '.m3u';

        return Response::make($m3uContent, 200, [
            'Access-Control-Allow-Origin' => '*',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }
}
