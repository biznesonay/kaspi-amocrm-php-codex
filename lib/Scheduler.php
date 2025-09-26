<?php
declare(strict_types=1);

require_once __DIR__.'/Db.php';
require_once __DIR__.'/Logger.php';

final class Scheduler {
    /** @var array<string, array{interval:int, job:callable}> */
    private array $jobs = [];

    public function register(string $name, int $interval, callable $job): void {
        $this->jobs[$name] = [
            'interval' => $interval,
            'job' => $job,
        ];
    }

    public function runDueJobs(int $now): void {
        foreach ($this->jobs as $name => $meta) {
            $lastRun = Db::getSetting($this->lastRunKey($name));
            $lastRunTs = $lastRun !== null ? (int) $lastRun : null;
            if ($lastRunTs !== null && $now < $lastRunTs + $meta['interval']) {
                continue;
            }

            try {
                ($meta['job'])();
                Db::setSetting($this->lastRunKey($name), (string) $now);
            } catch (Throwable $e) {
                Logger::error('Scheduler job failed', [
                    'job' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function lastRunKey(string $name): string {
        return 'scheduler_last_run_'.$name;
    }
}
