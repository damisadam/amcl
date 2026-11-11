{{-- Case readiness / compliance panel (QA S8, H6). $checks from App\Services\Lending\ReadinessService::checks() --}}
@php($compact = $compact ?? false)
@php($blocking = collect($checks)->where('ok', false)->count())
<div class="rounded-lg border {{ $blocking ? 'border-amber-300 bg-amber-50/40' : 'border-brand-200 bg-brand-50/40' }} {{ $class ?? '' }}">
    <div class="flex items-center justify-between gap-2 border-b border-black/5 px-4 py-2.5">
        <p class="text-sm font-semibold text-ink">Readiness checklist</p>
        @if($blocking)<x-badge tone="amber">{{ $blocking }} open item{{ $blocking > 1 ? 's' : '' }}</x-badge>@else<x-badge tone="green">All checks passed</x-badge>@endif
    </div>
    <div @class(['grid gap-x-6 gap-y-1.5 p-4 text-sm', 'sm:grid-cols-2' => ! $compact])>
        @foreach(collect($checks)->groupBy('group') as $group => $items)
            <div class="{{ $compact ? '' : 'mb-2' }}">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">{{ $group }}</p>
                @foreach($items as $c)
                    <div class="flex items-start gap-2 py-0.5">
                        @if($c['ok'] === true)<span class="mt-0.5 font-bold text-brand-700" aria-label="passed">✓</span>
                        @elseif($c['ok'] === false)<span class="mt-0.5 font-bold text-red-600" aria-label="not met">✗</span>
                        @else<span class="mt-0.5 text-neutral-400" aria-label="not applicable yet">–</span>@endif
                        <div class="min-w-0"><span class="font-medium">{{ $c['label'] }}</span>@if($c['detail'])<span class="text-neutral-500"> — {{ $c['detail'] }}</span>@endif</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
