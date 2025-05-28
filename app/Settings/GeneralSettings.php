<?php

namespace App\Settings;

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $navigation_position = 'left';
    public ?bool $show_breadcrumbs = true;
    public ?bool $show_logs = false;
    public ?bool $show_api_docs = false;
    public ?bool $show_queue_manager = false;
    public ?string $content_width = MaxWidth::ScreenExtraLarge->value;
    public ?string $ffmpeg_user_agent = 'VLC/3.0.21 LibVLC/3.0.21';
    public ?bool $ffmpeg_debug = false;
    public ?int $ffmpeg_max_tries = 3;
    public ?string $ffmpeg_codec_video = null;
    public ?string $ffmpeg_codec_audio = null;
    public ?string $ffmpeg_codec_subtitles = null;
    public ?string $mediaflow_proxy_url = null;
    public ?string $mediaflow_proxy_port = null;
    public ?string $mediaflow_proxy_password = null;
    public ?string $mediaflow_proxy_user_agent = null;
    public ?bool $mediaflow_proxy_playlist_user_agent = false;
    public ?string $ffmpeg_path = null;
    public bool $ffmpeg_vaapi_enabled = false;
    public string $ffmpeg_vaapi_device = '/dev/dri/renderD128';
    public string $ffmpeg_vaapi_video_filter = 'scale_vaapi=format=nv12';
    public bool $ffmpeg_qsv_enabled = false;
    public ?string $ffmpeg_qsv_device = '/dev/dri/renderD128';
    public ?string $ffmpeg_qsv_video_filter = 'vpp_qsv=format=nv12';
    public ?string $ffmpeg_qsv_encoder_options = null;
    public ?string $ffmpeg_qsv_additional_args = null;

    public static function group(): string
    {
        return 'general';
    }
}
