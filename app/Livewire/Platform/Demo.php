<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Models\DemoResetRequest;
use App\Services\Platform\DemoResetService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Demo-environment reset with two-person approval. Refused in production or when demo mode is off. */
#[Layout('layouts.platform')]
class Demo extends Component
{
    use HandlesActions;

    public const PHRASE = DemoResetService::PHRASE;

    public string $confirmation = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->need('platform.demo.reset');
    }

    public function allowed(): bool
    {
        return DemoResetService::allowed();
    }

    public function requestReset(DemoResetService $svc): void
    {
        $this->need('platform.demo.reset');
        if ($this->attempt(fn () => $svc->request(auth()->user(), $this->confirmation, $this->reason), 'Reset requested. A second platform administrator must approve it.')) {
            $this->reset(['confirmation', 'reason']);
        }
    }

    public function approve(int $id, DemoResetService $svc): void
    {
        $this->need('platform.demo.reset');
        $this->attempt(fn () => $svc->approve(DemoResetRequest::findOrFail($id), auth()->user()), 'Approved – the demo reset job is queued.');
    }

    public function reject(int $id, DemoResetService $svc): void
    {
        $this->need('platform.demo.reset');
        $req = DemoResetRequest::findOrFail($id);
        $this->attempt(fn () => $svc->reject($req, auth()->user(), (int) $req->requested_by === (int) auth()->id()), 'Request closed.');
    }

    public function credentials(): array
    {
        $path = base_path('docs/DEMO_CREDENTIALS.md');
        if (! $this->allowed() || ! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '|') || preg_match('/^\|\s*-+/', $line)) {
                continue;
            }
            $rows[] = array_map('trim', array_slice(explode('|', $line), 1, -1));
        }

        return $rows;
    }

    public function render()
    {
        return view('livewire.platform.demo', [
            'credentials' => $this->credentials(),
            'pending' => DemoResetRequest::with('requester')->where('status', 'pending')->latest('id')->get(),
            'history' => DemoResetRequest::with(['requester', 'decider'])->where('status', '!=', 'pending')->latest('id')->take(10)->get(),
        ]);
    }
}
