{{-- Real-time UX via Reverb. Falls back silently to polling (wire:poll) if the websocket is unavailable. --}}
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
document.addEventListener('livewire:init', () => {
    try {
        if (!window.Echo) return;
        @auth
        window.Echo.private('App.Models.User.{{ auth()->id() }}').notification((n) => {
            Livewire.dispatch('realtime'); if (n.title) window.dispatchEvent(new CustomEvent('toast', { detail: { message: n.title, tone: 'blue' } }));
        }).listen('.wallet_transaction_completed', () => Livewire.dispatch('realtime'));
        @if(auth()->user()->isStaff())
        window.Echo.private('tenant.{{ auth()->user()->tenant_id }}.ops')
            .listen('.application_submitted', () => Livewire.dispatch('realtime'))
            .listen('.application_status_changed', () => Livewire.dispatch('realtime'))
            .listen('.approval_task_assigned', () => Livewire.dispatch('realtime'))
            .listen('.payment_status_updated', () => Livewire.dispatch('realtime'))
            .listen('.disbursement_status_updated', () => Livewire.dispatch('realtime'))
            .listen('.complaint_received', () => Livewire.dispatch('realtime'))
            .listen('.field_visit_assigned', () => Livewire.dispatch('realtime'));
        @endif
        @if(auth()->user()->isPlatform())
        window.Echo.private('platform.health').listen('.platform_health_alert', (e) => window.dispatchEvent(new CustomEvent('toast', { detail: { message: e.message, tone: 'red' } })));
        @endif
        @endauth
    } catch (e) { console.warn('Realtime unavailable, using polling', e); }
});
</script>
