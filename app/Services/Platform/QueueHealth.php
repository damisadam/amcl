<?php

namespace App\Services\Platform;

use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Failed-job review and safe retry. Financial jobs (simulated callbacks, postings) are idempotent:
 * callbacks are de-duplicated by provider event id and ledger postings by idempotency key, so a retry
 * can never credit or post twice.
 */
class QueueHealth
{
    public function failedJobs(int $limit = 50, string $search = ''): array
    {
        return DB::table('failed_jobs')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('queue', 'like', "%$search%")->orWhere('exception', 'like', "%$search%")->orWhere('uuid', $search)))
            ->orderByDesc('id')->limit($limit)->get()->map(function ($j) {
                $payload = json_decode($j->payload, true);

                return (object) [
                    'uuid' => $j->uuid, 'queue' => $j->queue, 'connection' => $j->connection, 'failed_at' => $j->failed_at,
                    'job' => $payload['displayName'] ?? 'Job',
                    'exception' => mb_substr(strtok((string) $j->exception, "\n"), 0, 300),
                ];
            })->all();
    }

    public function retry(string $uuid): void
    {
        $this->assertExists($uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Auditor::record('platform.job_retried', null, [], ['uuid' => $uuid]);
    }

    public function forget(string $uuid): void
    {
        $this->assertExists($uuid);
        Artisan::call('queue:forget', ['id' => $uuid]);
        Auditor::record('platform.job_forgotten', null, [], ['uuid' => $uuid]);
    }

    public function redisStatus(): array
    {
        try {
            $pong = Redis::connection()->ping();

            return ['ok' => true, 'message' => is_string($pong) ? $pong : 'PONG'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Redis unavailable: '.class_basename($e)];
        }
    }

    public function reverbStatus(): array
    {
        $configured = config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'));

        return ['ok' => $configured, 'message' => $configured
            ? 'Reverb configured at '.config('broadcasting.connections.reverb.options.host').':'.config('broadcasting.connections.reverb.options.port').' (UI falls back to polling if offline)'
            : 'Broadcasting driver is "'.config('broadcasting.default').'" – real-time disabled, polling fallback active'];
    }

    private function assertExists(string $uuid): void
    {
        if (! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            throw new \App\Exceptions\DomainException('That failed job no longer exists.');
        }
    }
}
