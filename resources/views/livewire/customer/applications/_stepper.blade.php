{{-- Application tracker: Submitted → Review → Approval → KFS → Sign → Cooling-off → Disbursed (ref: docs/ui-references/customer-applications.png). --}}
@php
    $steps = [__('Submitted'), __('Review'), __('Approval'), __('KFS'), __('Sign'), __('Cooling-off'), __('Disbursed')];
    $dates = [0 => $app->first_submitted_at ?? $app->submitted_at, 2 => $app->approved_at, 3 => $app->kfs_acknowledged_at, 4 => $app->signed_at, 6 => $app->disbursed_at];
    $at = match ($app->status->value) {
        'draft' => -1, 'submitted', 'returned' => 0, 'under_review' => 1, 'pending_approval' => 2, 'approved' => 3, 'kfs_acknowledged' => 4,
        'cooling_off' => 5, 'ready_for_disbursement', 'disbursement_in_progress' => 6, 'disbursed' => 7, default => null,
    };
@endphp
@if($at !== null)
<nav aria-label="{{ __('Application progress') }}" class="overflow-x-auto pb-1">
    <ol class="flex min-w-[36rem] text-xs">
        @foreach($steps as $i => $label)
            @php($state = $at > $i || ($at === 7 && $i === 6) ? 'done' : ($at === $i ? 'now' : 'todo'))
            <li class="relative flex flex-1 flex-col items-center text-center" @if($state === 'now') aria-current="step" @endif>
                @if(! $loop->first)<span @class(['absolute end-1/2 top-3.5 h-0.5 w-full -translate-y-1/2', 'bg-brand-600' => $at >= $i, 'bg-neutral-200' => $at < $i]) aria-hidden="true"></span>@endif
                <span @class(['relative z-10 flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold',
                    'bg-brand-600 text-white' => $state === 'done', 'bg-white text-brand-700 ring-2 ring-brand-600 ring-offset-2' => $state === 'now', 'bg-white text-neutral-400 ring-1 ring-neutral-300' => $state === 'todo'])>
                    @if($state === 'done')<x-icon name="check" class="h-4 w-4"/>@else{{ $i + 1 }}@endif</span>
                <span @class(['mt-2 font-medium', 'text-ink' => $state !== 'todo', 'text-neutral-400' => $state === 'todo'])>{{ $label }}</span>
                @if(! empty($dates[$i]) && $state !== 'todo')<span class="mt-0.5 text-[11px] text-neutral-500">{{ $dates[$i]->format('d M Y') }}</span>@endif
            </li>
        @endforeach
    </ol>
    @if($app->status->value === 'returned')<p class="mt-2 text-xs text-amber-700">{{ __('Returned to you – please respond to the requests below and resubmit.') }}</p>@endif
</nav>
@endif
