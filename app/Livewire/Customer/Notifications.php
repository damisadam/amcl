<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\CustomerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.customer')]
class Notifications extends Component
{
    use CustomerContext, WithPagination;

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(except: '')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[On('realtime')]
    public function refresh(): void {}

    public function markAll(): void
    {
        $this->user()->unreadNotifications->markAsRead();
    }

    public function read(string $id): void
    {
        $this->user()->notifications()->findOrFail($id)->markAsRead();
    }

    public function render()
    {
        $u = $this->user();
        $items = $u->notifications()
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($this->filter === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('data', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->latest()->paginate(20);
        $total = $u->notifications()->count();
        $unread = $u->unreadNotifications()->count();

        return view('livewire.customer.notifications', ['items' => $items, 'total' => $total, 'unread' => $unread, 'read' => $total - $unread])->title(__('Notifications'));
    }
}
