<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings; // Ensured
use App\Services\FfmpegCodecService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set; // Added this line
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Enums\MaxWidth;

class Preferences extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public function mount(): void
    {
        parent::mount();
    }

    public function form(Form $form): Form
    {
        $ffmpegPath = config('proxy.ffmpeg_path');
        return $form
            ->schema([
                Forms\Components\Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Appearance')
                            ->schema([
                                Forms\Components\Select::make('navigation_position')
                                    ->label('Navigation position')
                                    ->helperText('Choose the position of primary navigation')
                                    ->options([
                                        'left' => 'Left',
                                        'top' => 'Top',
                                    ]),
                                Forms\Components\Toggle::make('show_breadcrumbs')
                                    ->label('Show breadcrumbs')
                                    ->helperText('Show breadcrumbs under the page titles'),
                                Forms\Components\Select::make('content_width')
                                    ->label('Max width of the page content')
                                    ->options(MaxWidth::class),
                            ]),
                        Forms\Components\Tabs\Tab::make('Proxy')
                            ->schema([
                                Forms\Components\Section::make('Internal Proxy')
                                    ->description('FFmpeg proxy settings')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->schema([
                                        Forms\Components\Toggle::make('ffmpeg_debug')
                                            ->label('Debug')
                                            ->columnSpan(1)
                                            ->helperText('When enabled FFmpeg will output verbose logging to the log file (/var/www/logs/ffmpeg-YYYY-MM-DD.log). When disabled, FFmpeg will only log errors.'),
                                        Forms\Components\Select::make('ffmpeg_path')
                                            ->label('FFmpeg')
                                            ->columnSpan(2)
                                            ->helperText('Which ffmpeg variant would you like to use.')
                                            ->options([
                                                'jellyfin-ffmpeg' => 'jellyfin-ffmpeg (default)',
                                                'ffmpeg' => 'ffmpeg (v6)',
                                            ])
                                            ->searchable()
                                            ->suffixIcon(fn() => !empty($ffmpegPath) ? 'heroicon-m-lock-closed' : null)
                                            ->disabled(fn() => !empty($ffmpegPath))
                                            ->hint(fn() => !empty($ffmpegPath) ? 'Already set by environment variable!' : null)
                                            ->dehydrated(fn() => empty($ffmpegPath))
                                            ->placeholder(fn() => empty($ffmpegPath) ? 'jellyfin-ffmpeg' : $ffmpegPath),
                                        Forms\Components\TextInput::make('ffmpeg_max_tries')
                                            ->label('Max tries')
                                            ->columnSpan(1)
                                            ->required()
                                            ->type('number')
                                            ->default(3)
                                            ->minValue(0)
                                            ->helperText('If the FFMpeg process crashes or fails for any reason, how many times should it try to reconnect before aborting?'),
                                        Forms\Components\TextInput::make('ffmpeg_user_agent')
                                            ->label('User agent')
                                            ->required()
                                            ->columnSpan(2)
                                            ->default('VLC/3.0.21 LibVLC/3.0.21')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->helperText('Fallback user agent (defaults to the streams Playlist user agent, when set).'),

                                        Forms\Components\Select::make('hardware_acceleration_method')
                                            ->label('Hardware Acceleration')
                                            ->options([
                                                'none' => 'None',
                                                'qsv' => 'Intel QSV',
                                                'vaapi' => 'VA-API',
                                            ])
                                            ->live()
                                            ->columnSpanFull()
                                            ->helperText('Choose the hardware acceleration method for FFmpeg.')
                                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                                $currentVideoCodec = $get('ffmpeg_codec_video');
                                                if ($currentVideoCodec === null) {
                                                    return; // Nothing selected, nothing to invalidate
                                                }

                                                // $state is the new hardware_acceleration_method
                                                $newValidCodecs = FfmpegCodecService::getVideoCodecs($state); 

                                                if (!array_key_exists($currentVideoCodec, $newValidCodecs)) {
                                                    // Reset to 'Copy Original' which is represented by an empty string
                                                    $set('ffmpeg_codec_video', ''); 
                                                }
                                            }),

                                        Forms\Components\TextInput::make('ffmpeg_vaapi_device')
                                            ->label('VA-API Device Path')
                                            ->columnSpan('full')
                                            ->default('/dev/dri/renderD128')
                                            ->placeholder('/dev/dri/renderD128')
                                            ->helperText('e.g., /dev/dri/renderD128 or /dev/dri/card0')
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'vaapi'),
                                        Forms\Components\TextInput::make('ffmpeg_vaapi_video_filter')
                                            ->label('VA-API Video Filter')
                                            ->columnSpan('full')
                                            ->default('scale_vaapi=format=nv12')
                                            ->placeholder('scale_vaapi=format=nv12')
                                            ->helperText("e.g., scale_vaapi=w=1280:h=720:format=nv12. Applied using -vf. Ensure 'format=' is usually nv12 or vaapi.")
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'vaapi'),

                                        Forms\Components\TextInput::make('ffmpeg_qsv_device')
                                            ->label('QSV Device Path')
                                            ->columnSpan('full')
                                            ->placeholder('/dev/dri/renderD128')
                                            ->helperText('e.g., /dev/dri/renderD128. This is passed to init_hw_device.')
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        Forms\Components\TextInput::make('ffmpeg_qsv_video_filter')
                                            ->label('QSV Video Filter (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('vpp_qsv=w=1280:h=720:format=nv12')
                                            ->helperText('e.g., vpp_qsv=w=1280:h=720:format=nv12 for scaling. Applied using -vf.')
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        Forms\Components\Textarea::make('ffmpeg_qsv_encoder_options')
                                            ->label('QSV Encoder Options (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('e.g., -profile:v high -g 90 -look_ahead 1')
                                            ->helperText('Additional options for the h264_qsv (or hevc_qsv) encoder.')
                                            ->rows(3)
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'qsv'),
                                        Forms\Components\Textarea::make('ffmpeg_qsv_additional_args')
                                            ->label('Additional QSV Arguments (Optional)')
                                            ->columnSpan('full')
                                            ->placeholder('e.g., -low_power 1 for some QSV encoders')
                                            ->helperText('Advanced: Additional FFmpeg arguments specific to your QSV setup. Use with caution.')
                                            ->rows(3)
                                            ->visible(fn (Get $get) => $get('hardware_acceleration_method') === 'qsv'),

                                        $this->makeCodecSelect('video', 'ffmpeg_codec_video', $form),
                                        $this->makeCodecSelect('audio', 'ffmpeg_codec_audio', $form),
                                        $this->makeCodecSelect('subtitle', 'ffmpeg_codec_subtitles', $form),
                                    ]),
                                Forms\Components\Section::make('MediaFlow Proxy')
                                    ->description('If you have MediaFlow Proxy installed, you can use it to proxy your m3u editor playlist streams. When enabled, the app will auto-generate URLs for you to use via MediaFlow Proxy.')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('mfproxy_git')
                                            ->label('GitHub')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true)
                                    ])
                                    ->schema([
                                        Forms\Components\TextInput::make('mediaflow_proxy_url')
                                            ->label('URL')
                                            ->columnSpan(1)
                                            ->placeholder('http://localhost'),
                                        Forms\Components\TextInput::make('mediaflow_proxy_port')
                                            ->label('Port')
                                            ->type('number')
                                            ->columnSpan(1)
                                            ->placeholder(8888),
                                        Forms\Components\TextInput::make('mediaflow_proxy_password')
                                            ->label('API Password')
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable(),
                                        Forms\Components\Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label('Use playlist user agent')
                                            ->inline(false)
                                            ->live()
                                            ->helperText('Appends the Playlist user agent. Disable to use a custom user agent for all requests.'),
                                        Forms\Components\TextInput::make('mediaflow_proxy_user_agent')
                                            ->label('User agent')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->columnSpan(2)
                                            ->hidden(fn(Get $get): bool => !!$get('mediaflow_proxy_playlist_user_agent')),
                                    ])
                            ]),
                        Forms\Components\Tabs\Tab::make('API')
                            ->schema([
                                Forms\Components\Section::make('API Settings')
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('manage_api_keys')
                                            ->label('Manage API Tokens')
                                            ->color('gray')
                                            ->icon('heroicon-s-key')
                                            ->iconPosition('before')
                                            ->size('sm')
                                            ->url('/profile'),
                                        Forms\Components\Actions\Action::make('view_api_docs')
                                            ->label('API Docs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/docs/api')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Forms\Components\Toggle::make('show_api_docs')
                                            ->label('Allow access to API docs')
                                            ->helperText('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.'),
                                    ])
                            ]),
                        Forms\Components\Tabs\Tab::make('Debugging')
                            ->schema([
                                Forms\Components\Section::make('Debugging')
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('test_websocket')
                                            ->label('Test WebSocket')
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->form([
                                                Forms\Components\TextInput::make('message')
                                                    ->label('Message')
                                                    ->required()
                                                    ->default('Testing WebSocket connection')
                                                    ->helperText('This message will be sent to the WebSocket server, and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.'),
                                            ])
                                            ->action(function (array $data): void {
                                                Notification::make()
                                                    ->success()
                                                    ->title("WebSocket Connection Test")
                                                    ->body($data['message'])
                                                    ->persistent()
                                                    ->broadcast(auth()->user());
                                            }),
                                        Forms\Components\Actions\Action::make('view_logs')
                                            ->label('View Logs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/logs')
                                            ->openUrlInNewTab(true),
                                        Forms\Components\Actions\Action::make('view_queue_manager')
                                            ->label('Queue Manager')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/horizon')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Forms\Components\Toggle::make('show_logs')
                                            ->label('Make log files viewable')
                                            ->helperText('When enabled you can view the log files using the "View Logs" button. When disabled, the logs endpoint will return a 403 (Unauthorized).'),
                                        Forms\Components\Toggle::make('show_queue_manager')
                                            ->label('Allow queue manager access')
                                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).'),
                                    ]),
                            ]),
                    ])
            ]);
    }

    private function makeCodecSelect(string $label, string $field, Form $form): Forms\Components\Select
    {
        $configKey = "proxy.{$field}";
        $configValue = config($configKey);

        return Forms\Components\Select::make($field)
            ->label(ucwords($label) . ' codec')
            ->helperText("Transcode {$label} streams to this codec.\nLeave blank to copy the original.")
            ->allowHtml()
            ->searchable()
            ->live()
            ->noSearchResultsMessage('No codecs found.')
            ->options(function (Get $get) use ($label) {
                $accelerationMethod = $get('hardware_acceleration_method');
                switch ($label) {
                    case 'video':
                        return FfmpegCodecService::getVideoCodecs($accelerationMethod);
                    case 'audio':
                        return FfmpegCodecService::getAudioCodecs($accelerationMethod);
                    case 'subtitle':
                        return FfmpegCodecService::getSubtitleCodecs($accelerationMethod);
                    default:
                        return [];
                }
            })
            ->placeholder(fn() => empty($configValue) ? 'copy' : $configValue)
            ->suffixIcon(fn() => !empty($configValue) ? 'heroicon-m-lock-closed' : null)
            ->disabled(fn() => !empty($configValue))
            ->hint(fn() => !empty($configValue) ? 'Already set by environment variable!' : null)
            ->dehydrated(fn() => empty($configValue));
    }

protected function mutateFormDataBeforeSave(array $data): array
{
    $settingsClass = static::$settings; // Gets App\Settings\GeneralSettings
    $reflectionClass = new \ReflectionClass($settingsClass);
    $definedProperties = $reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC);

    // Ensure all defined settings are present in the data, using current values if not submitted
    foreach ($definedProperties as $property) {
        $propertyName = $property->getName();
        if (!array_key_exists($propertyName, $data)) {
            // If the property is not in the submitted form data (e.g., it was hidden),
            // add its current value from the loaded settings ($this->settings).
            // $this->settings is the already hydrated settings object.
            $data[$propertyName] = $this->getSettingsRecord()->{$propertyName};
        }
    }

    // Explicitly set QSV fields to null if QSV is not the selected hardware acceleration method.
    // This is important if these fields were part of the form but are now hidden.
    if (isset($data['hardware_acceleration_method']) && $data['hardware_acceleration_method'] !== 'qsv') {
        $data['ffmpeg_qsv_device'] = null;
        $data['ffmpeg_qsv_video_filter'] = null;
        $data['ffmpeg_qsv_encoder_options'] = null;
        $data['ffmpeg_qsv_additional_args'] = null;
    }

    // Explicitly set VAAPI fields to null if VA-API is not the selected method.
    if (isset($data['hardware_acceleration_method']) && $data['hardware_acceleration_method'] !== 'vaapi') {
        $data['ffmpeg_vaapi_device'] = null;
        $data['ffmpeg_vaapi_video_filter'] = null;
    }
    
    // Convert empty strings from text inputs to null for nullable fields.
    // This should run after ensuring all keys are present.
    $nullableTextfields = [
        'ffmpeg_codec_video', 'ffmpeg_codec_audio', 'ffmpeg_codec_subtitles',
        'ffmpeg_vaapi_device', 'ffmpeg_vaapi_video_filter',
        'ffmpeg_qsv_device', 'ffmpeg_qsv_video_filter',
        'ffmpeg_qsv_encoder_options', 'ffmpeg_qsv_additional_args',
        'mediaflow_proxy_url', 'mediaflow_proxy_port', 'mediaflow_proxy_password',
        'mediaflow_proxy_user_agent', 'ffmpeg_path'
    ];

    foreach ($nullableTextfields as $field) {
        if (array_key_exists($field, $data) && $data[$field] === '') {
            $data[$field] = null;
        }
    }

    // Ensure 'hardware_acceleration_method' itself has a default if it was somehow missing
    // (though the first loop should cover it if it's a public property).
    if (!isset($data['hardware_acceleration_method'])) {
        // Attempt to get class default, fallback to 'none'
        $classDefaults = $reflectionClass->getDefaultProperties();
        $data['hardware_acceleration_method'] = $classDefaults['hardware_acceleration_method'] ?? 'none';
    }

    return $data;
}
}
