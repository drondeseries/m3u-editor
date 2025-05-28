<?php

namespace App\Settings;

use App\Services\FfmpegCodecService; // Added this line
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
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
    public ?string $hardware_acceleration_method = 'none';
    // public bool $ffmpeg_vaapi_enabled = false;
    public ?string $ffmpeg_vaapi_device = null;
    public ?string $ffmpeg_vaapi_video_filter = null;
    // public bool $ffmpeg_qsv_enabled = false;
    public ?string $ffmpeg_qsv_device = null;
    public ?string $ffmpeg_qsv_video_filter = null;
    public ?string $ffmpeg_qsv_encoder_options = null;
    public ?string $ffmpeg_qsv_additional_args = null;

    public static function group(): string
    {
        return 'general';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('General UI')
                    ->description('Configure the general user interface settings.')
                    ->schema([
                        Select::make('navigation_position')
                            ->label('Navigation Position')
                            ->options([
                                'left' => 'Left',
                                'top' => 'Top',
                                'right' => 'Right',
                            ])
                            ->default('left')
                            ->required(),
                        Toggle::make('show_breadcrumbs')
                            ->label('Show Breadcrumbs')
                            ->default(true),
                        Toggle::make('show_logs')
                            ->label('Show Logs Link in Navigation')
                            ->default(false),
                        Toggle::make('show_api_docs')
                            ->label('Show API Docs Link in Navigation')
                            ->default(false),
                        Toggle::make('show_queue_manager')
                            ->label('Show Queue Manager Link in Navigation')
                            ->default(false),
                        Select::make('content_width')
                            ->label('Default Content Width')
                            ->options(MaxWidth::class)
                            ->default(MaxWidth::ScreenExtraLarge->value)
                            ->required(),
                    ])->columns(2),

                Section::make('FFmpeg Settings')
                    ->description('General FFmpeg configuration.')
                    ->schema([
                        TextInput::make('ffmpeg_path')
                            ->label('FFmpeg Binary Path')
                            ->placeholder('/usr/bin/ffmpeg')
                            ->hint('Leave empty to use system PATH.'),
                        TextInput::make('ffmpeg_user_agent')
                            ->label('FFmpeg User Agent')
                            ->default('VLC/3.0.21 LibVLC/3.0.21')
                            ->required(),
                        Toggle::make('ffmpeg_debug')
                            ->label('Enable FFmpeg Debug Logging')
                            ->default(false),
                        TextInput::make('ffmpeg_max_tries')
                            ->label('FFmpeg Max Retries')
                            ->numeric()
                            ->default(3)
                            ->required(),
                    ])->columns(2),

                Section::make('Hardware Acceleration')
                    ->description('Configure hardware acceleration settings for FFmpeg.')
                    ->schema([
                        Select::make('hardware_acceleration_method')
                            ->label('Hardware Acceleration Method')
                            ->options([
                                'none' => 'None',
                                'vaapi' => 'VA-API (Linux)',
                                'qsv' => 'Intel QSV (Linux/Windows)',
                                // Add other methods like 'nvenc', 'videotoolbox' if supported later
                            ])
                            ->default('none')
                            ->reactive()
                            ->required(),
                        TextInput::make('ffmpeg_vaapi_device')
                            ->label('VA-API Device Path')
                            ->default('/dev/dri/renderD128')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'vaapi'),
                        TextInput::make('ffmpeg_vaapi_video_filter')
                            ->label('VA-API Video Filter')
                            ->placeholder('scale_vaapi=format=nv12')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'vaapi'),
                        TextInput::make('ffmpeg_qsv_device')
                            ->label('QSV Device Path (Intel GPU)')
                            ->default('/dev/dri/renderD128')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'qsv'),
                        TextInput::make('ffmpeg_qsv_video_filter')
                            ->label('QSV Video Filter')
                            ->placeholder('vpp_qsv=format=nv12')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'qsv'),
                        TextInput::make('ffmpeg_qsv_encoder_options')
                            ->label('QSV Encoder Options')
                            ->placeholder('e.g., -preset medium -look_ahead 1')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'qsv'),
                        TextInput::make('ffmpeg_qsv_additional_args')
                            ->label('QSV Additional FFmpeg Arguments')
                            ->placeholder('e.g., -init_hw_device qsv=hw -filter_hw_device hw')
                            ->visible(fn (callable $get) => $get('hardware_acceleration_method') === 'qsv'),
                    ])->columns(2),

                Section::make('Codec Settings')
                    ->description('Default codec settings for FFmpeg processing. Select "Default" to copy the original stream codec.')
                    ->schema([
                        Select::make('ffmpeg_codec_video')
                            ->label('Video Codec')
                            ->options(fn (callable $get) => FfmpegCodecService::getVideoCodecs($get('hardware_acceleration_method')))
                            ->default(null)
                            ->reactive(),
                        Select::make('ffmpeg_codec_audio')
                            ->label('Audio Codec')
                            ->options(fn (callable $get) => FfmpegCodecService::getAudioCodecs($get('hardware_acceleration_method')))
                            ->default(null)
                            ->reactive(),
                        Select::make('ffmpeg_codec_subtitles')
                            ->label('Subtitle Codec')
                            ->options(fn (callable $get) => FfmpegCodecService::getSubtitleCodecs($get('hardware_acceleration_method')))
                            ->default(null)
                            ->reactive(),
                    ])->columns(3),

                Section::make('Media Flow Proxy Settings')
                    ->description('Configure proxy settings for FFmpeg media requests.')
                    ->schema([
                        TextInput::make('mediaflow_proxy_url')
                            ->label('Proxy URL')
                            ->placeholder('socks5://user:pass@host:port or http://user:pass@host:port'),
                        TextInput::make('mediaflow_proxy_port')
                            ->label('Proxy Port (Alternative)')
                            ->numeric()
                            ->hint('Alternative port if not specified in URL. Not commonly used.'),
                        TextInput::make('mediaflow_proxy_password')
                            ->label('Proxy Password (Alternative)')
                            ->password()
                            ->hint('Alternative password if not specified in URL. Not commonly used.'),
                        TextInput::make('mediaflow_proxy_user_agent')
                            ->label('Proxy User Agent for Media Streams'),
                        Toggle::make('mediaflow_proxy_playlist_user_agent')
                            ->label('Use Proxy User Agent for Playlists (M3U8/MPD)')
                            ->helperText('If enabled, the above User Agent will also be used for fetching playlist files. Otherwise, the default FFmpeg User Agent is used for playlists.'),
                    ])->columns(2),
            ]);
    }
}
