<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\FailoverChannel;
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
                if ($type !== 'custom') {
                    $channels = $playlist->channels()
                        ->where('enabled', true)
                        ->with(['epgChannel', 'tags'])
                        ->orderBy('sort')
                        ->orderBy('channel')
                        ->orderBy('title')
                        ->get()
                        ->map(function ($channel) {
                            // Wrap regular channels to have a consistent structure with processed failover channels
                            return (object)[
                                'is_failover' => false,
                                'original_channel' => $channel,
                                'id' => $channel->id,
                                'title_custom' => $channel->title_custom,
                                'title' => $channel->title,
                                'name_custom' => $channel->name_custom,
                                'name' => $channel->name,
                                'url_custom' => $channel->url_custom,
                                'url' => $channel->url,
                                'epgChannel' => $channel->epgChannel,
                                'channel_no_original' => $channel->channel,
                                'shift' => $channel->shift,
                                'group_original' => $channel->group,
                                'logo_type' => $channel->logo_type,
                                'logo' => $channel->logo,
                                'stream_id_custom' => $channel->stream_id_custom,
                                'stream_id' => $channel->stream_id,
                                'catchup' => $channel->catchup,
                                'catchup_source' => $channel->catchup_source,
                                'extvlcopt' => $channel->extvlcopt,
                                'kodidrop' => $channel->kodidrop,
                                'tags' => $channel->tags,
                            ];
                        });
                } else {
                    // Custom Playlist: Fetch regular and failover channels
                    $regularChannels = $playlist->channels()
                        ->where('enabled', true)
                        ->with(['epgChannel', 'tags'])
                        ->orderBy('sort')
                        ->orderBy('channel')
                        ->orderBy('title')
                        ->get();

                    $failoverChannels = $playlist->failoverChannels()
                        ->with(['sources' => function ($query) {
                            $query->orderBy('failover_channel_sources.order', 'asc')->with('epgChannel');
                        }, 'tags']) // Added 'tags' for failover channels
                        ->get();

                    $processedChannels = collect([]);

                    foreach ($regularChannels as $regChannel) {
                        $processedChannels->push((object)[
                            'is_failover' => false,
                            'original_channel' => $regChannel,
                            'id' => $regChannel->id,
                            'title_custom' => $regChannel->title_custom,
                            'title' => $regChannel->title,
                            'name_custom' => $regChannel->name_custom,
                            'name' => $regChannel->name,
                            'url_custom' => $regChannel->url_custom,
                            'url' => $regChannel->url,
                            'epgChannel' => $regChannel->epgChannel,
                            'channel_no_original' => $regChannel->channel,
                            'shift' => $regChannel->shift,
                            'group_original' => $regChannel->group,
                            'logo_type' => $regChannel->logo_type,
                            'logo' => $regChannel->logo,
                            'stream_id_custom' => $regChannel->stream_id_custom,
                            'stream_id' => $regChannel->stream_id,
                            'catchup' => $regChannel->catchup,
                            'catchup_source' => $regChannel->catchup_source,
                            'extvlcopt' => $regChannel->extvlcopt,
                            'kodidrop' => $regChannel->kodidrop,
                            'tags' => $regChannel->tags,
                        ]);
                    }

                    foreach ($failoverChannels as $fc) {
                        $primarySource = $fc->sources->first();

                        $fcName = $fc->tvg_name_override ?: ($primarySource ? ($primarySource->name_custom ?: $primarySource->name) : $fc->name);
                        $fcTitle = $fc->tvg_name_override ?: ($primarySource ? ($primarySource->title_custom ?: $primarySource->title) : $fc->name);

                        $fcLogo = $fc->tvg_logo_override;
                        if (empty($fcLogo) && $primarySource) {
                            if ($primarySource->logo_type === \App\Enums\ChannelLogoType::Epg && $primarySource->epgChannel) {
                                $fcLogo = $primarySource->epgChannel->icon ?? '';
                            } elseif ($primarySource->logo_type === \App\Enums\ChannelLogoType::Channel) {
                                $fcLogo = $primarySource->logo ?? '';
                            }
                        }
                        if (empty($fcLogo)) {
                            $fcLogo = url('/placeholder.png');
                        }

                        $fcTvgId = $fc->tvg_id_override ?: ($primarySource ? ($primarySource->stream_id_custom ?: $primarySource->stream_id) : '');
                        $fcTvgId = preg_replace(config('dev.tvgid.regex', '/[^A-Za-z0-9.-]/'), '', $fcTvgId);

                        $fcChannelNo = $fc->tvg_chno_override ?: ($primarySource ? $primarySource->channel : null);
                        $fcGroup = $primarySource ? ($primarySource->group_internal ?: $primarySource->group) : 'Failover';

                        $processedChannels->push((object)[
                            'is_failover' => true,
                            'original_channel' => $fc, // Store original failover channel
                            'id' => $fc->id,
                            'title_custom' => null,
                            'title' => $fcTitle,
                            'name_custom' => null,
                            'name' => $fcName,
                            'url_custom' => null,
                            'url' => route('stream.failover', ['failoverChannel' => $fc->id]),
                            'epgChannel' => null,
                            'channel_no_original' => $fcChannelNo,
                            'shift' => $primarySource ? $primarySource->shift : 0,
                            'group_original' => $fcGroup,
                            'logo_type' => null,
                            'logo' => $fcLogo,
                            'stream_id_custom' => null,
                            'stream_id' => $fcTvgId,
                            'catchup' => $primarySource ? $primarySource->catchup : null,
                            'catchup_source' => $primarySource ? $primarySource->catchup_source : null,
                            'extvlcopt' => $primarySource ? $primarySource->extvlcopt : [],
                            'kodidrop' => $primarySource ? $primarySource->kodidrop : [],
                            'tags' => $fc->tags,
                        ]);
                    }
                    $channels = $processedChannels;
                }

                // Output the enabled channels
                echo "#EXTM3U\n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                $idChannelBy = $playlist->id_channel_by;
                foreach ($channels as $channel) { // $channel is now a processed object
                    // Get the title and name
                    $title = $channel->title; // Already processed for failover
                    $name = $channel->name;   // Already processed for failover
                    
                    // URL and Proxy Logic
                    if ($channel->is_failover) {
                        $url = $channel->url; // Already set to the correct failover stream route
                    } else {
                        $url = $channel->original_channel->url_custom ?? $channel->original_channel->url;
                        if ($proxyEnabled) {
                            $url = ProxyFacade::getProxyUrlForChannel(
                                id: $channel->original_channel->id,
                                format: 'ts'
                            );
                        }
                    }

                    $epgData = $channel->is_failover ? null : $channel->epgChannel; // Failover channels use composed EPG data
                    $channelNo = $channel->channel_no_original;
                    $timeshift = $channel->shift ?? 0;
                    $group = $channel->group_original ?? '';

                    if (!$channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    
                    if ($type === 'custom') {
                        $customGroup = $channel->tags // Tags are on the processed object
                            ->where('type', $playlist->uuid)
                            ->first();
                        if ($customGroup) {
                            $group = $customGroup->getAttributeValue('name');
                        }
                    }

                    // Get the TVG ID
                    if ($channel->is_failover) {
                        $tvgId = $channel->stream_id; // Already processed TVG ID for failover
                    } else {
                        // Existing switch for regular channels
                        switch ($idChannelBy) {
                            case PlaylistChannelId::ChannelId:
                                $tvgId = $channelNo;
                                break;
                            case PlaylistChannelId::Name:
                                $tvgId = $channel->original_channel->name_custom ?? $channel->original_channel->name;
                                break;
                            case PlaylistChannelId::Title:
                                $tvgId = $channel->original_channel->title_custom ?? $channel->original_channel->title;
                                break;
                            default:
                                $tvgId = $channel->original_channel->stream_id_custom ?? $channel->original_channel->stream_id;
                                break;
                        }
                        // Make sure TVG ID only contains characters and numbers for regular channels
                        $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);
                    }
                    
                    // Get the icon
                    if ($channel->is_failover) {
                        $icon = $channel->logo; // Already processed logo for failover
                    } else {
                        $icon = '';
                        if ($channel->original_channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel) {
                            $icon = $channel->epgChannel->icon ?? '';
                        } elseif ($channel->original_channel->logo_type === ChannelLogoType::Channel) {
                            $icon = $channel->original_channel->logo ?? '';
                        }
                        if (empty($icon)) {
                            $icon = url('/placeholder.png');
                        }
                    }
                    
                    // Output the channel
                    $extInf = "#EXTINF:-1";
                    $currentCatchup = $channel->catchup;
                    $currentCatchupSource = $channel->catchup_source;

                    if ($currentCatchup) {
                        $extInf .= " catchup=\"$currentCatchup\"";
                    }
                    if ($currentCatchupSource) {
                        $extInf .= " catchup-source=\"$currentCatchupSource\"";
                    }
                    $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" timeshift=\"$timeshift\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$group\"";
                    echo "$extInf," . $title . "\n";
                    
                    $currentExtvlcopt = $channel->extvlcopt;
                    if ($currentExtvlcopt) {
                        foreach ($currentExtvlcopt as $extvlcopt) {
                            echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
                        }
                    }

                    $currentKodidrop = $channel->kodidrop;
                    if ($currentKodidrop) {
                        foreach ($currentKodidrop as $kodidrop) {
                            echo "#KODIPROP:{$kodidrop['key']}={$kodidrop['value']}\n";
                        }
                    }
                    echo $url . "\n";
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
        return response()->json($channels->transform(function (Channel $channel) use ($proxyEnabled, $idChannelBy, $autoIncrement, &$channelNumber) {
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

            // Example of more detailed response
            //            return [
            //                'GuideNumber' => $channel->channel_number ?? $streamId, // Channel number (e.g., "100")
            //                'GuideName'   => $channel->title_custom ?? $channel->title, // Channel name
            //                'URL'         => $url, // Stream URL
            //                'HD'          => $is_hd ? 1 : 0, // HD flag
            //                'VideoCodec'  => 'H264', // Set based on your stream format
            //                'AudioCodec'  => 'AAC', // Set based on your stream format
            //                'Favorite'    => $favorite ? 1 : 0, // Favorite flag
            //                'DRM'         => 0, // Assuming no DRM
            //                'Streaming'   => 'direct', // Direct stream or transcoding
            //            ];
        }));
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
