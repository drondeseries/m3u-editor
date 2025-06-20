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
    public ?string $ffmpeg_codec_video = '';
    public ?string $ffmpeg_codec_audio = null;
    public ?string $ffmpeg_codec_subtitles = null;
    public ?string $mediaflow_proxy_url = null;
    public ?string $mediaflow_proxy_port = null;
    public ?string $mediaflow_proxy_password = null;
    public ?string $mediaflow_proxy_user_agent = null;
    public ?bool $mediaflow_proxy_playlist_user_agent = false;
    public ?string $ffmpeg_path = null;
    public ?int $ffmpeg_hls_time = 4;
    public ?int $ffmpeg_ffprobe_timeout = 5;
    public ?int $hls_playlist_max_attempts = 10;
    public ?float $hls_playlist_sleep_seconds = 1.0;

    // New properties
    public bool $ffmpeg_input_copyts;
    public string $ffmpeg_input_analyzeduration;
    public string $ffmpeg_input_probesize;
    public string $ffmpeg_input_max_delay;
    public string $ffmpeg_input_fflags;
    public bool $ffmpeg_output_include_aud;
    public bool $ffmpeg_enable_print_graphs;
    public bool $ffmpeg_input_stream_loop;
    public bool $ffmpeg_disable_subtitles;
    public bool $ffmpeg_audio_disposition_default;

    // VAAPI and QSV settings
    public ?string $hardware_acceleration_method = 'none';
    public ?string $ffmpeg_custom_command_template = null;
    public ?string $ffmpeg_vaapi_device = null;
    public ?string $ffmpeg_vaapi_video_filter = null;
    public ?string $ffmpeg_qsv_device = null;
    public ?string $ffmpeg_qsv_video_filter = null;
    public ?string $ffmpeg_qsv_encoder_options = null;
    public ?string $ffmpeg_qsv_additional_args = null;

    // It's good practice to initialize defaults here if the migration doesn't always run first
    // or if these properties are ever accessed before a migration sets them.
    // However, the SettingsMigration `add` method usually handles the default in the DB.
    // For clarity and safety, especially for non-nullable types, let's add them.
    // Spatie Laravel Settings will use these if the DB value is null or not set.

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Default values for new properties if not already set from DB
        // Note: Spatie settings automatically handles defaults specified in migrations
        // for properties that are not explicitly initialized here.
        // However, for boolean properties, it's good to be explicit if they are not nullable.
        $this->ffmpeg_input_copyts = $attributes['ffmpeg_input_copyts'] ?? true;
        $this->ffmpeg_input_analyzeduration = $attributes['ffmpeg_input_analyzeduration'] ?? '3M';
        $this->ffmpeg_input_probesize = $attributes['ffmpeg_input_probesize'] ?? '3M';
        $this->ffmpeg_input_max_delay = $attributes['ffmpeg_input_max_delay'] ?? '5000000';
        $this->ffmpeg_input_fflags = $attributes['ffmpeg_input_fflags'] ?? 'nobuffer+igndts+discardcorruptts+fillwallclockdts';
        $this->ffmpeg_output_include_aud = $attributes['ffmpeg_output_include_aud'] ?? true;
        $this->ffmpeg_enable_print_graphs = $attributes['ffmpeg_enable_print_graphs'] ?? false;
        $this->ffmpeg_input_stream_loop = $attributes['ffmpeg_input_stream_loop'] ?? false;
        $this->ffmpeg_disable_subtitles = $attributes['ffmpeg_disable_subtitles'] ?? true;
        $this->ffmpeg_audio_disposition_default = $attributes['ffmpeg_audio_disposition_default'] ?? true;
    }


    public static function group(): string
    {
        return 'general';
    }
}
