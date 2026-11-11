<?php

namespace App\Livewire\Customer\Support;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Complaint;
use App\Services\Support\ComplaintService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.customer')]
class Show extends Component
{
    use CustomerContext;

    public Complaint $complaint;

    public string $message = '';

    public function mount(Complaint $complaint): void
    {
        $this->complaint = $this->own($complaint);
    }

    #[On('realtime')]
    public function refresh(): void {}

    public function reply(ComplaintService $svc): void
    {
        $this->validate(['message' => 'required|string|min:2|max:3000']);
        if ($this->attempt(fn () => $svc->reply($this->complaint->fresh(), $this->user(), $this->message), __('Message sent.'))) {
            $this->message = '';
        }
    }

    public function render()
    {
        $c = $this->own(Complaint::with(['assignee', 'related'])->findOrFail($this->complaint->id));

        return view('livewire.customer.support.show', ['c' => $c, 'messages' => $c->messages()->where('is_internal', false)->with('user')->get()])->title(__('Complaint').' '.$this->complaint->ticket_no);
    }
}
