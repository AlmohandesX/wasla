<?php

namespace App\Console\Commands;

use App\Enums\ModerationDecision;
use App\Services\Moderation\ModerationPipeline;
use Illuminate\Console\Command;

class EvaluateModeration extends Command
{
    protected $signature = 'wasla:evaluate-moderation {file : CSV with a "text,expected" header; expected is block or allow}';

    protected $description = 'Run a labelled CSV through the moderation pipeline and print block rates and every wrong result';

    public function handle(ModerationPipeline $pipeline): int
    {
        $file = (string) $this->argument('file');
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false) {
            $this->error("Cannot read $file.");

            return self::FAILURE;
        }

        $rows = [];
        $sawHeader = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                $this->warn(ltrim($line, '# '));

                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if (! $sawHeader && ($cells[0] ?? '') === 'text') {
                $sawHeader = true;

                continue;
            }
            $expected = strtolower(trim($cells[1] ?? ''));
            if (! in_array($expected, ['block', 'allow'], true) || trim($cells[0] ?? '') === '') {
                $this->error('Bad row (expected "text,block|allow"); the row text is not echoed.');

                return self::FAILURE;
            }
            $rows[] = ['text' => $cells[0], 'expected' => $expected];
        }

        $count = ['block' => 0, 'allow' => 0];
        $blocked = ['block' => 0, 'allow' => 0];
        $wrong = [];
        foreach ($rows as $row) {
            // Dry run: nothing is written to the moderation log and the daily cap is untouched.
            $result = $pipeline->check($row['text'], [], true, false);
            $got = $result->decision === ModerationDecision::Block ? 'block' : 'allow';
            $count[$row['expected']]++;
            $blocked[$row['expected']] += $got === 'block' ? 1 : 0;
            if ($got !== $row['expected']) {
                $wrong[] = sprintf('WRONG expected=%s got=%s: %s [%s]', $row['expected'], $got, $row['text'], $result->reason ?? $result->source);
            }
        }

        $this->info(sprintf('Rows: %d (%d expected block, %d expected allow)', count($rows), $count['block'], $count['allow']));
        $this->line(sprintf('Block rate on expected-block rows: %d/%d', $blocked['block'], $count['block']));
        $this->line(sprintf('Block rate on expected-allow rows: %d/%d', $blocked['allow'], $count['allow']));
        foreach ($wrong as $line) {
            $this->line($line);
        }
        $this->line(sprintf('Wrong results: %d', count($wrong)));

        return self::SUCCESS;
    }
}
