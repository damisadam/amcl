<div>
    <x-page-header :title="ucfirst($v->visit_type).' visit · '.$v->customer->full_name" :subtitle="$v->scheduled_for->format('l, d M Y').' · '.$v->officer?->name" :back="route('ops.visits.index')"/>
    <div class="-mt-4 mb-5"><x-status :value="$v->status"/></div>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Visit details">
                <x-dl :items="['Customer' => $v->customer->full_name, 'Mobile' => $v->customer->mobile, 'Address' => $v->address, 'Application' => $v->application?->application_no,
                    'Product' => $v->application?->product?->name, 'Asset' => $v->application?->asset_description, 'Checked in' => $v->checked_in_at?->format('d M Y H:i'),
                    'GPS' => $v->latitude ? $v->latitude.', '.$v->longitude.($v->gps_accuracy_m ? ' (±'.round($v->gps_accuracy_m).' m)' : '') : null,
                    'Distance from address' => $v->latitude ? ($v->distance_from_address_m !== null ? number_format($v->distance_from_address_m).' m' : 'No reference location on file for this address') : null]"/>
                @if($v->distance_from_address_m !== null && $v->distance_from_address_m > $distanceFlag)<x-alert tone="red" class="mt-3">Check-in was {{ number_format($v->distance_from_address_m) }} m from the customer's recorded address – verify before relying on this report.</x-alert>@endif
                @if($v->latitude)<a class="link mt-2 inline-block text-sm" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $v->latitude }}&mlon={{ $v->longitude }}#map=17/{{ $v->latitude }}/{{ $v->longitude }}">Open location map</a>@endif
            </x-card>

            @if($isOfficer && in_array($v->status->value, ['scheduled', 'in_progress']))
                <x-card title="1. Check in at location" subtitle="A GPS fix (accuracy {{ $maxAccuracy }} m or better) is required as proof of presence (LMS §7.4).">
                    @if($v->status->value === 'scheduled' && ! $isToday)
                        <x-alert class="mb-3" title="Not scheduled for today">This visit is scheduled for {{ $v->scheduled_for->format('l, d M Y') }}. Check-in is only accepted on that date — ask your supervisor to reschedule it if you are visiting on another day.</x-alert>
                    @endif
                    <div x-data="{ busy: false, msg: '', ok: false }" class="flex flex-wrap items-center gap-3">
                        <button type="button" class="btn-dark" x-on:click="busy = true; msg=''; ok = false; if (!navigator.geolocation) { msg = 'This device cannot provide a location. Check-in requires GPS.'; busy = false; return; }
                            navigator.geolocation.getCurrentPosition(p => { $wire.set('lat', p.coords.latitude); $wire.set('lng', p.coords.longitude); $wire.set('accuracy', p.coords.accuracy);
                                ok = p.coords.accuracy <= {{ $maxAccuracy }}; msg = 'Location ' + p.coords.latitude.toFixed(5) + ', ' + p.coords.longitude.toFixed(5) + ' (±' + Math.round(p.coords.accuracy) + ' m)' + (ok ? '' : ' – accuracy too low, move outdoors and capture again'); busy = false; },
                            e => { msg = 'Location permission denied. Allow location access in your browser to check in.'; busy = false; }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 })" :disabled="busy"><x-icon name="map" class="h-4 w-4"/><span x-text="busy ? 'Locating…' : 'Capture GPS'"></span></button>
                        @if($v->status->value === 'scheduled')<button wire:click="checkIn" class="btn-primary" @disabled(! $isToday) x-bind:disabled="! ok || {{ $isToday ? 'false' : 'true' }}">Check in</button>@else<x-badge tone="green">Checked in {{ $v->checked_in_at?->format('H:i') }}</x-badge>@endif
                        <span class="text-xs" :class="ok ? 'text-brand-700' : 'text-red-600'" x-text="msg"></span>
                    </div>
                </x-card>
                <x-card title="2. Proof of visit" subtitle="Photos of premises / signed forms. At least one is required before submission.">
                    <form wire:submit="uploadProof" class="flex flex-wrap items-center gap-3">
                        <input type="file" accept="image/*" capture="environment" wire:model="photo" class="field-input max-w-xs">
                        <button class="btn-secondary" wire:loading.attr="disabled">Upload</button>
                        @error('photo')<p class="field-error w-full">{{ $message }}</p>@enderror
                    </form>
                </x-card>
                <x-card title="3. Findings & recommendation">
                    <form wire:submit="submit" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-select label="Address matches" wire:model="findings.address_match" :options="['1' => 'Yes', '0' => 'No']"/>
                            <x-select label="Living conditions" wire:model="findings.living_conditions" placeholder="—" :options="['good' => 'Good', 'average' => 'Average', 'poor' => 'Poor']"/>
                            <x-select label="Business conditions" wire:model="findings.business_conditions" placeholder="—" :options="['good' => 'Good', 'average' => 'Average', 'poor' => 'Poor', 'not_applicable' => 'Not applicable']"/>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach(['met_applicant' => 'Met applicant in person', 'cnic_sighted' => 'Original CNIC sighted', 'residence_owned' => 'Residence owned (not rented)', 'business_operational' => 'Business operational', 'asset_present' => 'Asset / collateral present', 'neighbour_confirmed' => 'Neighbour confirmed residence'] as $k => $label)
                                <x-checkbox wire:model="checklist.{{ $k }}" :label="$label"/>
                            @endforeach
                        </div>
                        <x-input label="Neighbour verification" wire:model="findings.neighbor_verification" placeholder="Name / remarks of neighbour contacted"/>
                        <x-textarea label="Remarks" wire:model="findings.remarks" rows="4" required/>
                        <x-select label="Recommendation" wire:model="findings.recommendation" placeholder="Select" :options="['positive' => 'Positive', 'follow_up' => 'Needs follow-up', 'negative' => 'Negative']" required/>
                        <button class="btn-primary w-full sm:w-auto" wire:loading.attr="disabled">Submit visit report</button>
                    </form>
                </x-card>
            @elseif($v->status->value === 'completed')
                <x-card title="Visit report">
                    <x-dl :items="['Recommendation' => ucfirst(str_replace('_', ' ', $v->recommendation)), 'Address match' => $v->address_match ? 'Yes' : 'No', 'Living conditions' => $v->living_conditions,
                        'Business conditions' => $v->business_conditions, 'Neighbour verification' => $v->neighbor_verification, 'Submitted' => $v->submitted_at?->format('d M Y H:i')]"/>
                    @if($v->checklist)
                        {{-- M18: plain pass / fail lines, not removable-looking chips --}}
                        <ul class="mt-4 grid gap-1.5 text-sm sm:grid-cols-2">
                            @foreach($v->checklist as $k => $ok)
                                <li class="flex items-center gap-2">@if($ok)<span class="font-bold text-brand-700" aria-hidden="true">✓</span><span>{{ \Illuminate\Support\Str::headline($k) }}</span><span class="text-xs text-brand-700">Yes</span>
                                    @else<span class="font-bold text-red-600" aria-hidden="true">✗</span><span>{{ \Illuminate\Support\Str::headline($k) }}</span><span class="text-xs text-red-600">No</span>@endif</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-3 whitespace-pre-line text-sm">{{ $v->remarks }}</p>
                </x-card>
            @endif
        </div>
        <div class="space-y-6">
            <x-card title="Evidence">
                @forelse($v->documents as $d)
                    <a href="{{ route('documents.show', $d) }}" target="_blank" class="link block text-sm">{{ $d->title }}</a><p class="mb-2 text-xs text-neutral-500">{{ $d->created_at->format('d M Y H:i') }}</p>
                @empty <p class="text-sm text-neutral-500">No evidence uploaded yet.</p> @endforelse
            </x-card>
            @can('field_visits.assign')@if($v->status->value === 'scheduled')
                <x-card title="Manage visit">
                    <div class="space-y-3">
                        <x-input label="Reschedule to" type="date" wire:model="rescheduleDate"/>
                        <x-select label="Reassign officer" wire:model="reassignTo" :options="$officers" placeholder="Keep current"/>
                        <button wire:click="reschedule" class="btn-secondary w-full">Save changes</button>
                        <x-textarea label="Cancellation reason" wire:model="cancelReason" rows="2"/>
                        <button wire:click="cancel" wire:confirm="Cancel this visit?" class="btn-danger w-full">Cancel visit</button>
                    </div>
                </x-card>
            @endif @endcan
        </div>
    </div>
</div>
