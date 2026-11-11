<div wire:poll.30s>
    <x-page-header title="KYC & AML review" subtitle="Manual review of identity mismatches, low face-match scores and sanctions/PEP potential matches."/>
    @php($tabs = ['identity' => 'Identity review ('.$counts['identity'].')'] + ($canAml ? ['aml' => 'AML/CFT matches ('.$counts['aml'].')'] : []) + ['expiry' => 'Expiring documents ('.$counts['expiry'].')'])
    <x-tabs :tabs="$tabs" :active="$tab"/>
    <x-card :padding="false">
        @if($tab === 'expiry')
            <x-table>
                <x-slot:head><x-th>Document</x-th><x-th>Owner</x-th><x-th>Expiry</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($rows as $d)
                    <tr><td>{{ $d->type?->name }}</td>
                        <td>@if($d->documentable instanceof \App\Models\Customer)<a class="link" href="{{ route('ops.customers.show', $d->documentable) }}" wire:navigate>{{ $d->documentable->full_name }}</a>@else{{ class_basename($d->documentable_type) }} #{{ $d->documentable_id }}@endif</td>
                        <td><span @class(['font-semibold', 'text-red-600' => $d->isExpired(), 'text-amber-700' => ! $d->isExpired()])>{{ $d->expires_on->format('d M Y') }}</span> <span class="text-xs text-neutral-500">({{ $d->expires_on->diffForHumans() }})</span></td>
                        <td>@if($d->isExpired())<x-badge tone="red">Expired</x-badge>@else<x-badge tone="amber">Expiring</x-badge>@endif</td></tr>
                @empty <x-empty colspan="4" title="No documents expiring in the next 45 days"/> @endforelse
            </x-table>
        @else
            <x-table>
                <x-slot:head><x-th>Customer</x-th><x-th>Findings</x-th><x-th>Since</x-th><x-th></x-th></x-slot:head>
                @forelse($rows as $c)
                    <tr><td><a class="link" href="{{ route('ops.customers.show', ['customer' => $c, 'tab' => 'kyc']) }}" wire:navigate>{{ $c->full_name }}</a><div class="text-xs text-neutral-500">{{ $c->cif_no }} · {{ $c->maskedCnic() }}</div></td>
                        <td class="text-xs">@foreach($c->kycVerifications as $k)
                            <div>{{ $k->step->label() }}: <x-status :value="$k->status"/> @if($k->score)score {{ number_format($k->score, 1) }}@endif {{ $k->result['note'] ?? $k->result['message'] ?? '' }}
                                @foreach(($k->result['hits'] ?? []) as $h)<span class="text-red-700">{{ $h['list'] }} ({{ $h['score'] }})</span>@endforeach</div>
                        @endforeach</td>
                        <td class="text-xs">{{ $c->updated_at->diffForHumans() }}</td>
                        <td class="text-right">@if($tab === 'aml')@can('aml.review')<button wire:click="open({{ $c->id }}, 'aml')" class="btn-primary btn-sm">Review</button>@endcan
                            @else @can('kyc.review')<button wire:click="open({{ $c->id }}, 'identity')" class="btn-primary btn-sm">Review</button>@endcan @endif</td></tr>
                @empty <x-empty colspan="4" title="Queue is empty" description="Nothing is waiting for manual review."/> @endforelse
            </x-table>
        @endif
        @if(method_exists($rows, 'links')){{ $rows->links() }}@endif
    </x-card>

    <x-modal show="showReview" :title="$reviewKind === 'aml' ? 'AML/CFT decision' : 'Identity review decision'" maxWidth="xl">
        @if($subject)
            <div class="mb-3 flex flex-wrap items-center gap-2 text-sm"><strong>{{ $subject->full_name }}</strong> · {{ $subject->cif_no }} · DOB {{ $subject->date_of_birth?->format('d M Y') }}
                <span class="ml-auto flex gap-1.5"><span class="text-xs text-neutral-500">KYC</span><x-status :value="$subject->kyc_status"/>@if($canAml)<span class="text-xs text-neutral-500">AML</span><x-status :value="$subject->aml_status"/>@endif</span></div>
            @if($reviewKind === 'identity' && $canAml && $subject->aml_status === \App\Enums\AmlStatus::PotentialMatch)
                <x-alert tone="amber" class="mb-3">An AML/PEP potential match is also open. Approving identity records your decision, but KYC stays <strong>Pending review</strong> until Compliance clears the AML match.</x-alert>
            @endif
            @if($reviewKind === 'aml')<x-alert tone="amber" class="mb-3">Confirming a match fails KYC and blacklists the customer. Clearing records the justification permanently.</x-alert>@endif

            {{-- Evidence (H4) --}}
            <div class="mb-4 space-y-4 text-sm">
                @if($sensitive && $evidence['images']->isNotEmpty())
                    <div class="grid grid-cols-3 gap-2">
                        @foreach($evidence['images'] as $img)
                            <a href="{{ route('documents.show', $img) }}" target="_blank" class="block overflow-hidden rounded-lg border border-neutral-200"><img src="{{ route('documents.show', $img) }}" alt="{{ $img->type->name }}" class="h-24 w-full object-cover"><span class="block bg-neutral-50 px-2 py-1 text-[11px]">{{ $img->type->name }}</span></a>
                        @endforeach
                    </div>
                @elseif(! $sensitive)<p class="text-xs text-neutral-500">CNIC and selfie images require the "view sensitive customer data" permission.</p>@endif
                @if($reviewKind === 'identity')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs font-semibold uppercase text-neutral-500">NADRA Verisys (simulated)</p>
                            @if($n = $evidence['nadra'])<p class="mt-1"><x-status :value="$n->status"/> match: <strong>{{ $n->result['match'] ?? '—' }}</strong></p>
                                <p class="text-xs">Name match: {{ ($n->result['name_match'] ?? null) === false ? '✗ No' : '✓ Yes' }} · DOB match: {{ ($n->result['dob_match'] ?? null) === false ? '✗ No' : '✓ Yes' }} · CNIC: {{ $n->result['cnic_status'] ?? '—' }}</p>
                                @if(!empty($n->result['note']))<p class="mt-1 text-xs text-amber-800">{{ $n->result['note'] }}</p>@endif
                            @else<p class="text-xs text-neutral-500">Not run</p>@endif</div>
                        <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs font-semibold uppercase text-neutral-500">Face match / liveness</p>
                            @if($f = $evidence['face'])<p class="mt-1"><x-status :value="$f->status"/> score <strong>{{ number_format((float) ($f->result['face_match_score'] ?? 0) * 100, 0) }}%</strong> (threshold {{ (int) (($f->result['threshold'] ?? 0.8) * 100) }}%)</p>
                                <p class="text-xs">Liveness: {{ str_replace('_', ' ', $f->result['liveness'] ?? '—') }}</p>
                            @else<p class="text-xs text-neutral-500">Not run</p>@endif</div>
                    </div>
                    <div><p class="mb-1 text-xs font-semibold uppercase text-neutral-500">CNIC OCR vs. profile</p>
                        <table class="w-full text-xs"><thead><tr class="text-left text-neutral-500"><th class="py-1">Field</th><th>Profile</th><th>Read from CNIC</th><th></th></tr></thead><tbody>
                        @foreach($evidence['ocr'] as $row)
                            @php($mask = $row['field'] === 'Cnic' && ! $sensitive)
                            <tr class="border-t border-neutral-100"><td class="py-1 font-medium">{{ $row['field'] === 'Cnic' ? 'CNIC' : $row['field'] }}</td><td>{{ $mask ? $subject->maskedCnic() : ($row['profile'] ?? '—') }}</td><td>{{ $mask ? '•••' : ($row['ocr'] ?? '—') }}</td>
                                <td>@if($row['match'] === true)<span class="text-brand-700">✓</span>@elseif($row['match'] === false)<span class="font-semibold text-red-600">✗ differs</span>@endif</td></tr>
                        @endforeach
                        </tbody></table></div>
                @elseif($canAml && ($am = $evidence['aml']))
                    <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs font-semibold uppercase text-neutral-500">Screening hits (simulated data)</p>
                        @foreach(($am->result['hits'] ?? []) as $h)<p class="mt-1"><strong>{{ $h['name'] ?? '' }}</strong> — {{ $h['list'] }} · {{ $h['category'] ?? '' }} · score {{ $h['score'] }}</p>@endforeach
                        <p class="mt-1 text-xs text-neutral-500">Lists checked: {{ implode(', ', $am->result['lists_checked'] ?? []) }}</p></div>
                @endif
            </div>
        @endif
        <x-textarea label="Decision notes (audited)" wire:model="notes" rows="4" required/>
        <x-slot:footer>
            <button class="btn-secondary" x-on:click="open=false">Cancel</button>
            <button wire:click="decide(false)" class="btn-danger">{{ $reviewKind === 'aml' ? 'Confirm match' : 'Reject' }}</button>
            <button wire:click="decide(true)" class="btn-primary">{{ $reviewKind === 'aml' ? 'Clear match' : 'Approve' }}</button>
        </x-slot:footer>
    </x-modal>
</div>
