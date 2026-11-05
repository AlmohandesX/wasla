<?php

namespace App\Jobs;

use App\Models\Wave;
use App\Services\Tags\WaveService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateWaveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public int $waveId) {}

    public function handle(WaveService $waves): void
    {
        $wave = Wave::find($this->waveId);

        if ($wave !== null) {
            $waves->generate($wave);
        }
    }

    public function failed(Throwable $e): void
    {
        Wave::whereKey($this->waveId)->update(['status' => 'failed']);
    }
}
