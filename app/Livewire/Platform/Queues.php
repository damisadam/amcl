<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Services\Platform\QueueHealth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.platform')]
class Queues extends Component
{
    use HandlesActions;

    public string $search = '';

    public function mount(): void
    {
        $this->need('platform.health.view');
    }

    public function retry(string $uuid, QueueHealth $q): void
    {
        $this->need('platform.health.view');
        $this->attempt(fn () => $q->retry($uuid), 'Job pushed back onto its queue.');
    }

    public function forget(string $uuid, QueueHealth $q): void
    {
        $this->need('platform.health.view');
        $this->attempt(fn () => $q->forget($uuid), 'Failed job removed.');
    }

    public function render(QueueHealth $q)
    {
        return view('livewire.platform.queues', ['jobs' => $q->failedJobs(100, trim($this->search)), 'redis' => $q->redisStatus(), 'reverb' => $q->reverbStatus(),
            'queue' => config('queue.default')]);
    }
}
