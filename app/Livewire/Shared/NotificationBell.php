<?php

namespace App\Livewire\Shared;

use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    public bool $dark = false;

    #[On('realtime')]
    public function refreshBell(): void {}

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function open(string $id)
    {
        $n = auth()->user()->notifications()->findOrFail($id);
        $n->markAsRead();
        $url = $n->data['url'] ?? null;

        return $url && str_starts_with($url, '/') ? $this->redirect($url, navigate: true) : null;
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.shared.notification-bell', [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->take(8)->get(),
        ]);
    }
}
