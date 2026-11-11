@props(['label', 'value', 'hint' => null, 'tone' => 'green', 'href' => null, 'icon' => null, 'tinted' => false])
{{-- KPI card (ref: docs/ui-references/operations-dashboard.png): coloured left edge + tinted round icon. --}}
@php([$edge, $chip] = [
    'green' => ['border-l-brand-600', 'bg-brand-50 text-brand-700'],
    'red' => ['border-l-red-500', 'bg-red-50 text-red-600'],
    'amber' => ['border-l-amber-500', 'bg-amber-50 text-amber-600'],
    'black' => ['border-l-slate-700', 'bg-slate-100 text-slate-700'],
    'purple' => ['border-l-indigo-500', 'bg-indigo-50 text-indigo-600'],
    'blue' => ['border-l-sky-500', 'bg-sky-50 text-sky-600'],
    'gray' => ['border-l-neutral-400', 'bg-neutral-100 text-neutral-500'],
][$tone] ?? ['border-l-brand-600', 'bg-brand-50 text-brand-700'])
{{-- No icon given: infer one from the label so every KPI card in the app gets the reference look. First match wins. --}}
@php($icon ??= collect([
    'fail|error|exception|reject|blacklist|written off|unpaid' => 'x-circle', 'overdue|par|risk|late|escalat|sla' => 'exclamation',
    'pending|in progress|awaiting|due|next|queue|latency|turnaround|→' => 'clock', 'approved|resolved|success|matched|integrity|issued|shariah|paid on|paid early' => 'check-circle',
    'wallet|balance|held|available' => 'wallet', 'customer|user|tenant|agent' => 'users', 'transaction|transfer|flow' => 'arrows',
    'disburs|payout|payment|paid|received|collect|deposit|payable|receivable|outstanding|principal|profit|income|expense|amount|total|value|cash|instalment|wht|charity|fund|statement|difference|position' => 'cash',
    'loan|financing|order|product' => 'document-text', 'redis|reverb|connection|environment|mode|call' => 'database', 'apr|dbr|rate|score|grade' => 'chart',
    'suspend|inactive|reset' => 'pause', 'open|dispute|complaint' => 'chat',
])->first(fn ($i, $k) => preg_match('/'.$k.'/iu', strip_tags((string) $label))))
@php($wash = $tinted ? ['green' => 'from-brand-100/80 to-brand-50/20', 'red' => 'from-red-100/80 to-red-50/20', 'amber' => 'from-orange-100/80 to-orange-50/20', 'gray' => 'from-neutral-200/60 to-neutral-50/20', 'blue' => 'from-sky-100/80 to-sky-50/20', 'purple' => 'from-indigo-100/80 to-indigo-50/20', 'black' => 'from-slate-200/60 to-slate-50/20'][$tone] ?? '' : '')
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->merge(['class' => "card @container flex items-center gap-4 border-l-4 $edge px-4 py-3.5".($wash ? " bg-gradient-to-r $wash" : '').($href ? ' transition hover:-translate-y-px hover:shadow-md' : '')]) }}>
    @if($icon)<span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-full @[14rem]:flex {{ $chip }}"><x-icon :name="$icon" class="h-6 w-6"/></span>@endif
    <div class="min-w-0">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500">{{ $label }}</p>
        <p class="mt-0.5 whitespace-nowrap text-base font-bold text-ink tabular @[12rem]:text-lg @[18rem]:text-xl">{{ $value }}</p>
        @if($hint)<p class="mt-0.5 text-xs text-neutral-500">{{ $hint }}</p>@endif
    </div>
</{{ $href ? 'a' : 'div' }}>
