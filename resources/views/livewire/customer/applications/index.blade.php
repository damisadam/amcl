<div>
    <x-page-header :title="__('My Applications')" :subtitle="__('Track and manage your financing applications. View status, details and next steps.')">
        <x-slot:actions><a href="{{ route('app.products') }}" wire:navigate class="btn-primary px-5 py-2.5"><x-icon name="plus" class="h-4 w-4"/>{{ __('New application') }}</a></x-slot:actions>
    </x-page-header>
    <div class="space-y-4">
        @forelse($applications as $a)
            @php($url = $a->status->value === 'draft' ? route('app.apply', $a->product_id).'?application='.$a->id : route('app.applications.show', $a))
            {{-- Next-step panel text per status; the detail page holds the actual actions. --}}
            @php($hint = match ($a->status->value) {
                'draft' => [__('Draft saved'), __('Finish and submit your application when you are ready.'), 'document-text', 'neutral'],
                'returned' => [__('Action needed'), __('Our team asked for more information. Respond and resubmit.'), 'alert-circle', 'amber'],
                'approved' => [__('Approved'), __('Review and acknowledge your Key Fact Statement.'), 'check-circle', 'green'],
                'kfs_acknowledged' => [__('Sign your documents'), __('Read each document, then sign with a one-time code.'), 'document-text', 'green'],
                'cooling_off' => [__('Cooling-off period'), __('You can cancel free of charge until :t.', ['t' => $a->cooling_off_ends_at?->format('d M H:i')]), 'clock', 'amber'],
                'disbursed' => [__('Application completed'), __('Your financing has been disbursed. You can view the details and manage it from your financing section.'), 'document-text', 'green'],
                'rejected' => [__('Not approved'), __('This application was not approved. See the details for the reason.'), 'x-circle', 'red'],
                'withdrawn', 'cancelled' => [__('Closed'), __('This application is no longer active.'), 'x-circle', 'neutral'],
                default => [__('In progress'), __('We are processing your application. We will notify you of every step.'), 'clock', 'neutral'],
            })
            <article class="card p-5" wire:key="app-{{ $a->id }}">
                <a href="{{ $url }}" wire:navigate class="group flex items-center gap-4">
                    <x-product-art :category="$a->product->category" class="hidden h-20 w-20 rounded-full sm:flex"/>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-ink group-hover:text-brand-800">{{ $a->product->localizedName() }}</h2>
                        <p class="text-sm text-neutral-500"><bdi>{{ $a->application_no }}</bdi> · {{ $a->created_at->format('d M Y') }}</p>
                        <p class="mt-1 text-sm text-neutral-500">{{ __('Amount') }}: <span class="font-semibold text-ink"><x-money :amount="$a->approved_amount ?? $a->requested_amount"/></span>
                            <span class="mx-2 text-neutral-300">|</span>{{ __('Tenure') }}: <span class="font-semibold text-ink">{{ $a->tenure }}</span></p>
                    </div>
                    <x-status :value="$a->status" class="px-3! py-1! text-sm! font-semibold"/>
                    <x-icon name="chevron-right" class="h-5 w-5 shrink-0 text-neutral-400 group-hover:text-ink rtl:rotate-180"/>
                </a>
                <div class="mt-4 border-t border-neutral-100 pt-4">@include('livewire.customer.applications._stepper', ['app' => $a])</div>
                @php($tones = ['green' => 'bg-brand-50/70 [&_.ic]:bg-brand-100 [&_.ic]:text-brand-700', 'amber' => 'bg-amber-50/80 [&_.ic]:bg-amber-100 [&_.ic]:text-amber-700', 'red' => 'bg-red-50/70 [&_.ic]:bg-red-100 [&_.ic]:text-red-600', 'neutral' => 'bg-neutral-50 [&_.ic]:bg-neutral-100 [&_.ic]:text-neutral-600'])
                <div class="mt-4 flex flex-col gap-3 rounded-xl p-4 sm:flex-row sm:items-center {{ $tones[$hint[3]] }}">
                    <span class="ic flex h-12 w-12 shrink-0 items-center justify-center rounded-full"><x-icon :name="$hint[2]" class="h-6 w-6"/></span>
                    <div class="min-w-0 flex-1"><p class="font-semibold text-ink">{{ $hint[0] }}</p><p class="text-sm text-neutral-600">{{ $hint[1] }}</p></div>
                    <a href="{{ $a->status->value === 'disbursed' && $a->loanAccount ? route('app.financing.show', $a->loanAccount) : $url }}" wire:navigate class="btn-secondary shrink-0 border-brand-600 px-5 text-brand-800 hover:bg-brand-50">{{ __('View details') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
                </div>
            </article>
        @empty
            <div class="card"><x-empty icon="document-text" :title="__('No applications yet')" :description="__('Start an application from the product catalogue.')"><a href="{{ route('app.products') }}" wire:navigate class="btn-primary">{{ __('Browse products') }}</a></x-empty></div>
        @endforelse
        {{ $applications->links() }}
    </div>
</div>
