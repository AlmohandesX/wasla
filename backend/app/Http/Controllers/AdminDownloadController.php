<?php

namespace App\Http\Controllers;

use App\Models\Wave;
use App\Services\Concerns\AuditsAdminActions;
use App\Services\Payments\SalesExporter;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin-only file downloads. Neither file has a public URL: both are read on demand behind the admin check. */
class AdminDownloadController extends Controller
{
    use AuditsAdminActions;

    public function package(Request $request, Wave $wave): StreamedResponse
    {
        $this->authorizeAdmin($request);

        $disk = Storage::disk('local');
        abort_unless($wave->status === 'ready' && $wave->package_path !== null && $disk->exists($wave->package_path), 404);

        // The zip holds every tag's token, so each download leaves a trail.
        $this->audit($request->user(), 'wave.package_download', 'wave', $wave->id, 'package download');

        return $disk->download($wave->package_path, sprintf('wave-%03d.zip', $wave->number), ['Cache-Control' => 'no-store, private']);
    }

    public function sales(Request $request, SalesExporter $exporter): StreamedResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Whole Istanbul days; the exporter compares them in UTC.
        $from = Carbon::createFromFormat('!Y-m-d', $data['from'], SalesExporter::TIMEZONE)->startOfDay();
        $to = Carbon::createFromFormat('!Y-m-d', $data['to'], SalesExporter::TIMEZONE)->endOfDay();

        return $exporter->toCsv($from, $to, $request->user());
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user !== null && $user->canAccessPanel(Filament::getPanel('admin')), 403);
    }
}
