<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        // Fetch the playlist
        $type = 'standard';
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $type = 'merged';
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $type = 'custom';
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Check auth
        $auth = $playlist->playlistAuths()->where('enabled', true)->first();
        if ($auth) {
            if (
                $request->get('username') !== $auth->username ||
                $request->get('password') !== $auth->password
            ) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.m3u';

        // Check if proxy enabled
        if ($request->has('proxy')) {
            $proxyEnabled = $request->input('proxy') === 'true';
        } else {
            $proxyEnabled = $playlist->enable_proxy;
        }

        // Get ll active channels
        return response()->stream(
            function () use ($playlist, $proxyEnabled, $type) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->with(['epgChannel', 'tags'])
                    ->orderBy('sort')
                    ->orderBy('channel')
                    ->orderBy('title')
                    ->get();

                // Fetch Merged Channels if the playlist is a CustomPlaylist
                $mergedChannels = [];
                if ($type === 'custom' && method_exists($playlist, 'mergedChannels')) {
                    $mergedChannels = $playlist->mergedChannels()->get();
                }

                // Output the enabled channels
                echo "#EXTM3U\n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                $idChannelBy = $playlist->id_channel_by;

                // Process regular channels
                foreach ($channels as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $url = $channel->url_custom ?? $channel->url;
                    $epgData = $channel->epgChannel ?? null;
                    $channelNo = $channel->channel;
                    $timeshift = $channel->shift ?? 0;
                    $group = $channel->group ?? '';
                    if (!$channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    if ($proxyEnabled) {
                        $url = ProxyFacade::getProxyUrlForChannel(
                            id: $channel->id,
                            format: 'ts'
                        );
                    }
                    if ($type === 'custom') {
                        $customGroup = $channel->tags
                            ->where('type', $playlist->uuid)
                            ->first();
                        if ($customGroup) {
                            $group = $customGroup->getAttributeValue('name');
                        }
                    }

                    // Get the TVG ID
                    switch ($idChannelBy) {
                        case PlaylistChannelId::ChannelId:
                            $tvgId = $channelNo;
                            break;
                        case PlaylistChannelId::Name:
                            $tvgId = $channel->name_custom ?? $channel->name;
                            break;
                        case PlaylistChannelId::Title:
                            $tvgId = $channel->title_custom ?? $channel->title;
                            break;
                        default:
                            $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                            break;
                    }

                    // Get the icon
                    $icon = '';
                    if ($channel->logo_type === ChannelLogoType::Epg && $epgData) {
                        $icon = $epgData->icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? '';
                    }
                    if (empty($icon)) {
                        $icon = url('/placeholder.png');
                    }

                    // Make sure TVG ID only contains characters and numbers
                    $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                    // Output the channel
                    $extInf = "#EXTINF:-1";
                    if ($channel->catchup) {
                        $extInf .= " catchup=\"$channel->catchup\"";
                    }
                    if ($channel->catchup_source) {
                        $extInf .= " catchup-source=\"$channel->catchup_source\"";
                    }
                    $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" timeshift=\"$timeshift\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$group\"";
                    echo "$extInf," . $title . "\n";
                    if ($channel->extvlcopt) {
                        foreach ($channel->extvlcopt as $extvlcopt) {
                            echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
                        }
                    }
                    if ($channel->kodidrop) {
                        foreach ($channel->kodidrop as $kodidrop) {
                            echo "#KODIPROP:{$kodidrop['key']}={$kodidrop['value']}\n";
                        }
                    }
                    echo $url . "\n";
                }

                // Process merged channels
                foreach ($mergedChannels as $mergedChannel) {
                    $mergedChannelTitle = $mergedChannel->name;
                    // Use a distinct prefix for merged channel tvg-id to avoid conflicts
                    $mergedChannelTvgId = "mergedchannel_" . $mergedChannel->id;
                    $mergedChannelName = $mergedChannel->name;
                    // Merged channels might not have a direct EPG mapping yet, use placeholder or name
                    $mergedChannelIcon = url('/placeholder.png'); // Placeholder icon
                    // Group title for merged channels, can be customized
                    $mergedChannelGroup = "Merged Channels";
                    // Merged channels don't have traditional channel numbers from M3U, timeshift, or catchup in the same way
                    // For now, we'll omit tvg-chno, timeshift. Catchup could be added if MergedChannel model supports it.

                    $mergedUrl = route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts']);

                    $extInf = "#EXTINF:-1 tvg-id=\"{$mergedChannelTvgId}\" tvg-name=\"{$mergedChannelName}\" tvg-logo=\"{$mergedChannelIcon}\" group-title=\"{$mergedChannelGroup}\"";
                    echo "$extInf,{$mergedChannelTitle}\n";
                    echo $mergedUrl . "\n";
                }

            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }

    public function hdhr(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check if playlist exists
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Setup the HDHR device info
        $deviceInfo = $this->getDeviceInfo($playlist);
        $deviceInfoXml = collect($deviceInfo)->map(function ($value, $key) {
            return "<$key>$value</$key>";
        })->implode('');
        $xmlResponse = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root>$deviceInfoXml</root>";

        // Return the XML response to mimic the HDHR device
        return response($xmlResponse)->header('Content-Type', 'application/xml');
    }

    public function hdhrOverview(Request $request, string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check auth
        $auth = $playlist->playlistAuths()->where('enabled', true)->first();
        if ($auth) {
            if (
                $request->get('username') !== $auth->username ||
                $request->get('password') !== $auth->password
            ) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        return view('hdhr', [
            'playlist' => $playlist,
        ]);
    }

    public function hdhrDiscover(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check if playlist exists
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Return the HDHR device info
        return $this->getDeviceInfo($playlist);
    }

    public function hdhrLineup(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Get all active channels
        $channels = $playlist->channels()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('channel')
            ->orderBy('title')
            ->get();

        // Check if proxy enabled
        $proxyEnabled = $playlist->enable_proxy;
        $idChannelBy = $playlist->id_channel_by;
        $autoIncrement = $playlist->auto_channel_increment;
        $channelNumber = $autoIncrement ? $playlist->channel_start - 1 : 0;

        // Determine playlist type
        $playlistType = 'standard'; // Default
        if ($playlist instanceof \App\Models\MergedPlaylist) {
            $playlistType = 'merged';
        } elseif ($playlist instanceof \App\Models\CustomPlaylist) {
            $playlistType = 'custom';
        }

        $directChannelsData = $channels->transform(function (Channel $channel) use ($proxyEnabled, $idChannelBy, $autoIncrement, &$channelNumber) {
            $url = $channel->url_custom ?? $channel->url;
            if ($proxyEnabled) {
                $url = ProxyFacade::getProxyUrlForChannel(
                    id: $channel->id,
                    format: 'ts'
                );
            }
            $channelNo = $channel->channel;
            if (!$channelNo && $autoIncrement) {
                $channelNo = ++$channelNumber;
            }
            // Get the TVG ID
            switch ($idChannelBy) {
                case PlaylistChannelId::ChannelId:
                    $tvgId = $channelNo;
                    break;
                case PlaylistChannelId::Name:
                    $tvgId = $channel->name_custom ?? $channel->name;
                    break;
                case PlaylistChannelId::Title:
                    $tvgId = $channel->title_custom ?? $channel->title;
                    break;
                default:
                    $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                    break;
            }
            return [
                'GuideNumber' => (string)$tvgId,
                'GuideName' => $channel->title_custom ?? $channel->title,
                'URL' => $url,
            ];
        })->values()->all(); // Get as a plain array

        // Fetch and process Merged Channels if the playlist is a CustomPlaylist
        $mergedChannelsData = [];
        if ($playlistType === 'custom' && method_exists($playlist, 'mergedChannels')) {
            // MergedChannel model does not have an 'enabled' status, so fetch all.
            $mergedChannels = $playlist->mergedChannels()->get();
            foreach ($mergedChannels as $mergedChannel) {
                $mergedChannelsData[] = [
                    'GuideNumber' => $mergedChannel->name, // Changed as per requirement
                    'GuideName'   => $mergedChannel->name,
                    'URL'         => route('mergedChannel.stream', ['mergedChannelId' => $mergedChannel->id, 'format' => 'ts'])
                ];
            }
        }

        $finalLineup = array_merge($directChannelsData, $mergedChannelsData);
        return response()->json($finalLineup);
    }

    public function hdhrLineupStatus(string $uuid)
    {
        // No need to fetch, status is same for all...
        return response()->json([
            'ScanInProgress' => 0,
            'ScanPossible' => 1,
            'Source' => 'Cable',
            'SourceList' => ['Cable'],
        ]);
    }

    private function getDeviceInfo($playlist)
    {
        // Return the HDHR device info
        $uuid = $playlist->uuid;
        $tunerCount = $playlist->streams;
        $deviceId = substr($uuid, 0, 8);
        return [
            'DeviceID' => $deviceId,
            'FriendlyName' => "{$playlist->name} HDHomeRun",
            'ModelNumber' => 'HDTC-2US',
            'FirmwareName' => 'hdhomerun3_atsc',
            'FirmwareVersion' => '20200101',
            'DeviceAuth' => 'test_auth_token',
            'BaseURL' => route('playlist.hdhr.overview', $uuid),
            'LineupURL' => route('playlist.hdhr.lineup', $uuid),
            'TunerCount' => $tunerCount,
        ];
    }
}
