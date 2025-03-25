<?php

namespace App\Http\Controllers;

use App\Models\Epg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EpgFileController extends Controller
{
    public function __invoke(string $uuid)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();
        $filePath = null;
        if ($epg->url && str_starts_with($epg->url, 'http')) {
            $filePath = Storage::disk('local')->path($epg->file_path);
        } else if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
            $filePath = Storage::disk('local')->path($epg->uploads);
        } else if ($epg->url) {
            $filePath = $epg->url;
        }
        if (!file_exists($filePath)) {
            abort(404);
        }

        // Generate a filename
        $filename = Str::slug($epg->name) . '.xml';

        // Setup the file stream
        $stream = fopen($filePath, 'r');

        // Return the original file
        return response()->stream(
            function () use ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192); // Read in 8KB chunks
                    flush(); // Ensure immediate output
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/xml',
            ]
        );
    }
}
