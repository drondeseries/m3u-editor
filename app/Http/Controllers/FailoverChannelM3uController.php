<?php

namespace App\Http\Controllers;

use App\Models\FailoverChannel;
use App\Models\Channel; // Might be needed for type hinting or constants
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response; // For streaming response
use Illuminate\Support\Str; // For Str::slug
use App\Enums\ChannelLogoType; // Ensure this is present

// TODO: Import other necessary classes like PlaylistChannelId, ProxyFacade if needed later.

class FailoverChannelM3uController extends Controller
{
    // PIVOT_COLUMNS constant is no longer strictly needed for TVG overrides by this method,
    // but might be used by other methods or if pivot data for 'order' is ever explicitly fetched.
    // For now, it can remain as it doesn't harm.
    private const PIVOT_COLUMNS = [
        'order',
        // 'override_tvg_id', // Removed as example, these are no longer from pivot
        // 'override_tvg_logo',
        // 'override_tvg_name',
        // 'override_tvg_chno',
        // 'override_tvg_guide_stationid'
    ];

    public function generate(Request $request, FailoverChannel $failoverChannel)
    {
        // Fetch primary source to use for fallbacks if overrides are not set
        // and for other attributes like catchup, timeshift, original group.
        $primarySource = $failoverChannel->sources()
            // No longer need ->withPivot(self::PIVOT_COLUMNS) for TVG overrides from pivot
            ->wherePivot('order', 1)
            ->first();

        if (!$primarySource) {
            // No primary source, M3U entry needs to reflect this or be minimal.
            // Use FailoverChannel's own overrides if they exist, or sensible defaults.
            $m3uContent = "#EXTM3U\n";
            $tvgNameDisplay = $failoverChannel->tvg_name_override ?: $failoverChannel->name;
            // Ensure tvg_id_override is processed by the regex if it exists.
            $tvgIdDisplay = $failoverChannel->tvg_id_override;
            if (empty($tvgIdDisplay)) { // If no override, generate from name
                $tvgIdDisplay = Str::slug($failoverChannel->name, '.');
            }
            $tvgIdDisplay = preg_replace(config('dev.tvgid.regex', '/[^A-Za-z0-9.-]/'), '', $tvgIdDisplay);
            
            $extinfTitle = $tvgNameDisplay ?: 'Unnamed Failover';

            $extInfLine = "#EXTINF:-1";
            if (!empty($tvgIdDisplay)) $extInfLine .= " tvg-id=\"{$tvgIdDisplay}\"";
            if (!empty($tvgNameDisplay)) $extInfLine .= " tvg-name=\"{$tvgNameDisplay}\"";
            // Add other direct TVG overrides from $failoverChannel if they exist
            if (!empty($failoverChannel->tvg_logo_override)) $extInfLine .= " tvg-logo=\"{$failoverChannel->tvg_logo_override}\"";
            if (!empty($failoverChannel->tvg_chno_override)) $extInfLine .= " tvg-chno=\"{$failoverChannel->tvg_chno_override}\"";
            // tvg_guide_stationid_override is not directly used in M3U EXTINF but good to have if needed for other contexts.
            // if (!empty($failoverChannel->tvg_guide_stationid_override)) $extInfLine .= " tvg-guide-stationid=\"{$failoverChannel->tvg_guide_stationid_override}\"";
            $extInfLine .= ",{$extinfTitle}";

            $m3uContent .= $extInfLine . "\n";
            // Stream URL still points to the FailoverStreamController
            $m3uContent .= route('stream.failover', ['failoverChannel' => $failoverChannel->id]) . "\n";
            
            $filename = Str::slug($failoverChannel->name ?: 'failover-no-source') . '.m3u';
            return Response::make($m3uContent, 200, [ // Return 200 but with minimal info
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]);
        }

        // All TVG attributes are now primarily from $failoverChannel itself
        $tvgName = $failoverChannel->tvg_name_override ?: ($primarySource->name_custom ?: $primarySource->name);
        
        $tvgLogo = $failoverChannel->tvg_logo_override;
        if (empty($tvgLogo)) { // Fallback for logo if override is empty
            if ($primarySource->logo_type === \App\Enums\ChannelLogoType::Epg && $primarySource->epgChannel) {
                $tvgLogo = $primarySource->epgChannel->icon ?? '';
            } elseif ($primarySource->logo_type === \App\Enums\ChannelLogoType::Channel) {
                $tvgLogo = $primarySource->logo ?? '';
            }
            if (empty($tvgLogo)) {
                // Ensure url() helper is available or use a fully qualified URL for placeholder.
                // For safety, using a generic placeholder path if url() isn't guaranteed in this context.
                $tvgLogo = '/placeholder.png'; // Or config('app.url').'/placeholder.png';
            }
        }
        
        $tvgId = $failoverChannel->tvg_id_override ?: ($primarySource->stream_id_custom ?: $primarySource->stream_id);
        $tvgId = preg_replace(config('dev.tvgid.regex', '/[^A-Za-z0-9.-]/'), '', $tvgId);

        $tvgChno = $failoverChannel->tvg_chno_override ?: $primarySource->channel;

        // Title for the M3U line
        $extinfTitle = $tvgName;

        // Stream URL points to the FailoverStreamController route
        $streamUrl = route('stream.failover', ['failoverChannel' => $failoverChannel->id]);

        // Construct #EXTINF line
        $extInfLine = "#EXTINF:-1";
        if (!empty($tvgId)) $extInfLine .= " tvg-id=\"{$tvgId}\"";
        if (!empty($tvgName)) $extInfLine .= " tvg-name=\"{$tvgName}\"";
        if (!empty($tvgLogo)) $extInfLine .= " tvg-logo=\"{$tvgLogo}\"";
        if ($tvgChno !== null && $tvgChno !== '') $extInfLine .= " tvg-chno=\"{$tvgChno}\"";
        
        // Original group from primary source (overrides don't apply to group-title in this design)
        $originalGroup = $primarySource->group_internal ?: $primarySource->group;
        if (!empty($originalGroup)) $extInfLine .= " group-title=\"{$originalGroup}\"";
        
        // Catchup and timeshift from primary source (no overrides for these)
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
