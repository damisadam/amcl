<div class="relative" x-data="{ o: false }" wire:poll.30s>
    <button x-on:click="o=!o" @class(['relative rounded-full p-2', 'hover:bg-white/10' => $dark, 'hover:bg-neutral-100 text-neutral-600' => ! $dark]) aria-label="{{ __('Notifications') }}">
        <x-icon name="bell"/>
        @if($unread)<span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
    </button>
    <div x-show="o" x-cloak x-on:click.outside="o=false" class="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-neutral-200 bg-white text-ink shadow-xl rtl:left-0 rtl:right-auto">
        <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-2.5"><p class="text-sm font-semibold">{{ __('Notifications') }}</p>
            @if($unread)<button wire:click="markAllRead" class="text-xs text-brand-700 hover:underline">{{ __('Mark all read') }}</button>@endif</div>
        <div class="max-h-96 overflow-y-auto">
            @forelse($items as $n)
                <button wire:click="open('{{ $n->id }}')" @class(['block w-full border-b border-neutral-50 px-4 py-3 text-left hover:bg-neutral-50', 'bg-brand-50/60' => ! $n->read_at])>
                    <p class="text-sm font-medium">{{ $n->data['title'] ?? \Illuminate\Support\Str::headline($n->data['event'] ?? 'Notification') }}</p>
                    @if(!empty($n->data['body']))<p class="mt-0.5 line-clamp-2 text-xs text-neutral-600">{{ $n->data['body'] }}</p>@endif
                    <p class="mt-1 text-[11px] text-neutral-400">{{ $n->created_at->diffForHumans() }}</p>
                </button>
            @empty
                <p class="px-4 py-8 text-center text-sm text-neutral-500">{{ __('You are all caught up.') }}</p>
            @endforelse
        </div>
    </div>
</div>
