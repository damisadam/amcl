<div class="space-y-5">
    <x-page-header :title="__('Notifications')" :subtitle="__('Stay updated with your financing, payments and account activity.')"/>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-center">
        @foreach([['bell', __('Total notifications'), $total, 'bg-brand-50 text-brand-700'], ['dot', __('Unread'), $unread, 'bg-brand-50 text-brand-600'], ['check', __('Read'), $read, 'bg-brand-50 text-brand-700']] as [$ic, $lbl, $n, $chip])
            <div class="card flex items-center gap-4 px-5 py-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $chip }}">@if($ic === 'dot')<span class="h-3.5 w-3.5 rounded-full bg-brand-600"></span>@else<x-icon :name="$ic" class="h-6 w-6"/>@endif</span>
                <div><p class="text-sm text-neutral-600">{{ $lbl }}</p><p class="text-2xl font-bold text-ink tabular">{{ $n }}</p></div>
            </div>
        @endforeach
        <button wire:click="markAll" @disabled(! $unread) class="btn-primary bg-brand-800 px-8 py-3 text-base hover:bg-brand-900"><x-icon name="check" class="h-5 w-5"/>{{ __('Mark all read') }}</button>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="inline-flex rounded-xl border border-neutral-200 bg-white p-1 text-sm shadow-xs" role="tablist">
            @foreach(['all' => __('All (:n)', ['n' => $total]), 'unread' => __('Unread (:n)', ['n' => $unread]), 'read' => __('Read (:n)', ['n' => $read])] as $k => $l)
                <button type="button" role="tab" aria-selected="{{ $filter === $k ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $k }}')" @class(['rounded-lg px-5 py-2 font-medium transition', 'bg-brand-800 text-white shadow-sm' => $filter === $k, 'text-neutral-700 hover:bg-brand-50' => $filter !== $k])><bdi>{{ $l }}</bdi></button>
            @endforeach
        </div>
        <x-input class="md:w-96" icon="search" type="search" wire:model.live.debounce.400ms="search" :placeholder="__('Search notifications (e.g. payment, application, Raast…)')" aria-label="{{ __('Search notifications') }}"/>
    </div>

    {{-- Icon from the notification's event key (wallet., complaint., application., loan., payment.). --}}
    @php($iconFor = fn ($e) => match (true) { str_contains($e, 'complaint') => 'chat', str_contains($e, 'transfer') => 'arrows', str_contains($e, 'bill') => 'document-text',
        str_contains($e, 'wallet') || str_contains($e, 'topup') => 'wallet', str_contains($e, 'kyc') || str_contains($e, 'security') => 'shield', str_contains($e, 'payment') || str_contains($e, 'instalment') => 'cash', default => 'document-text' })
    <div class="card overflow-hidden">
        <ul class="divide-y divide-neutral-100">
            @forelse($items as $n)
                @php($ref = $n->data['reference'] ?? (preg_match('/\b[A-Z]{2,4}-\d{4}-\d{6}\b/', ($n->data['title'] ?? '').' '.($n->data['body'] ?? ''), $m) ? $m[0] : null))
                <li wire:key="n-{{ $n->id }}" @class(['flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center', 'bg-brand-50/40' => ! $n->read_at])>
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span @class(['h-2.5 w-2.5 shrink-0 rounded-full', 'bg-brand-600' => ! $n->read_at, 'bg-transparent' => $n->read_at]) aria-hidden="true"></span>
                        <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', 'bg-brand-100 text-brand-700' => ! $n->read_at, 'bg-neutral-100 text-neutral-600' => $n->read_at])><x-icon :name="$iconFor((string) ($n->data['event'] ?? ''))" class="h-5 w-5"/></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">{{ $n->data['title'] ?? \Illuminate\Support\Str::headline($n->data['event'] ?? '') }}@if(! $n->read_at)<span class="sr-only"> ({{ __('unread') }})</span>@endif</p>
                            @if(!empty($n->data['body']))<p class="text-sm text-neutral-600">{{ $n->data['body'] }}</p>@endif
                        </div>
                    </div>
                    <div class="shrink-0 ps-[4.25rem] text-xs text-neutral-500 sm:w-56 sm:ps-0">@if($ref)<p class="text-neutral-700">{{ __('Ref') }}: <bdi>{{ $ref }}</bdi></p>@endif<p class="tabular">{{ $n->created_at->format('d M Y H:i') }} · {{ $n->created_at->diffForHumans() }}</p></div>
                    <div class="flex shrink-0 gap-2 ps-[4.25rem] sm:w-48 sm:justify-end sm:ps-0">
                        @if(!empty($n->data['url']) && str_starts_with($n->data['url'], '/'))<a href="{{ $n->data['url'] }}" wire:navigate wire:click="read('{{ $n->id }}')" class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-1.5 text-sm font-semibold text-brand-800 hover:bg-brand-100">{{ __('Open') }}</a>@endif
                        @if(! $n->read_at)<button wire:click="read('{{ $n->id }}')" class="rounded-lg border border-neutral-200 bg-white px-4 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">{{ __('Mark read') }}</button>@endif
                    </div>
                </li>
            @empty
                <li><x-empty icon="bell" :title="$search !== '' || $filter !== 'all' ? __('No matching notifications') : __('No notifications')" :description="__('Payment reminders, application updates and wallet alerts appear here.')"/></li>
            @endforelse
        </ul>
        {{ $items->links() }}
    </div>
</div>
