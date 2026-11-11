<div wire:poll.30s>
    <x-page-header :eyebrow="__('Good :p,', ['p' => now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening')])" :title="$greetName"
                   :subtitle="($user->branch?->name ?? '').' · '.now()->format('l, d M Y')"/>

    @if($myVisits->isNotEmpty() || ($taskView && $user->hasPermission('field_visits.perform')))
        <x-card :title="__('My field visits')" class="mb-6" :padding="false">
            <x-slot:actions><a href="{{ route('ops.visits.index') }}" wire:navigate class="link text-sm">{{ __('All visits') }}</a></x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Date</x-th><x-th>Customer</x-th><x-th>Type</x-th><x-th>Address</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($myVisits as $v)
                    <tr><td>{{ $v->scheduled_for->format('d M') }} @if($v->scheduled_for->isToday())<x-badge tone="amber">Today</x-badge>@endif</td>
                        <td><a class="link" href="{{ route('ops.visits.show', $v) }}" wire:navigate>{{ $v->customer->full_name }}</a></td>
                        <td>{{ ucfirst($v->visit_type) }}</td><td class="max-w-xs truncate">{{ $v->address }}</td><td><x-status :value="$v->status"/></td></tr>
                @empty <x-empty colspan="5" :title="__('No open visits assigned to you')"/> @endforelse
            </x-table>
        </x-card>
    @endif

    @if($myCases->isNotEmpty() || ($taskView && $user->hasPermission('recovery.work')))
        <x-card :title="__('My recovery cases')" class="mb-6" :padding="false">
            <x-table>
                <x-slot:head><x-th>Account</x-th><x-th>Customer</x-th><x-th align="right">DPD</x-th><x-th align="right">Outstanding</x-th></x-slot:head>
                @forelse($myCases as $l)
                    <tr><td>@if(Route::has('ops.recovery.show'))<a class="link" href="{{ route('ops.recovery.show', $l) }}" wire:navigate>{{ $l->account_no }}</a>@else{{ $l->account_no }}@endif</td>
                        <td>{{ $l->customer->full_name }}</td><td class="text-right"><x-badge :tone="$l->dpd > 30 ? 'red' : ($l->dpd > 0 ? 'amber' : 'green')">{{ $l->dpd }}</x-badge></td>
                        <td class="text-right"><x-money :amount="$l->totalOutstanding()"/></td></tr>
                @empty <x-empty colspan="4" :title="__('No cases assigned to you')"/> @endforelse
            </x-table>
        </x-card>
    @endif

    @if(! $taskView)
        {{-- KPI icons (ref: docs/ui-references/operations-dashboard.png) --}}
        @php($icons = ['In review / approval' => 'document-text', 'Approved this month' => 'check-circle', 'Approval turnaround' => 'clock', 'My approval tasks' => 'clipboard',
            'Active financings' => 'users', 'Outstanding principal' => 'database', 'Overdue accounts' => 'exclamation', 'Collections this month' => 'chart',
            'Wallet balances (liability)' => 'wallet', 'Wallet volume today' => 'arrows', 'Open complaints' => 'chat', 'KYC pending review' => 'user'])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($cards as [$label, $value, $hint, $tone, $href])
                <x-stat :label="__($label)" :value="$value" :hint="$hint" :tone="$tone" :href="$href" :icon="$icons[$label] ?? null"/>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            @if(!empty($charts['applications']))
                <x-card :title="__('Applications by status')" icon="users">
                    @php($max = max($charts['applications']) ?: 1)
                    <div class="space-y-0.5">
                        @foreach($charts['applications'] as $label => $count)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-40 shrink-0 truncate text-[13px] text-neutral-700">{{ $label }}</span>
                                <div class="h-3 flex-1 rounded-sm bg-neutral-100"><div class="h-3 rounded-sm bg-gradient-to-r from-brand-700 to-brand-500" style="width: {{ max(4, $count / $max * 100) }}%"></div></div>
                                <span class="w-10 text-right font-semibold tabular">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
            @if(!empty($charts['disbursements']))
                <x-card :title="__('Disbursed principal – last 6 months')" icon="chart">
                    @php($dmax = max($charts['disbursements']) ?: 1)
                    {{-- Nice y-axis: 4 gridlines at a 1/2/5×10^n step so the tallest bar always fits under the top line. --}}
                    @php($raw = $dmax / 4)
                    @php($mag = 10 ** floor(log10(max($raw, 1))))
                    @php($step = collect([1, 2, 5, 10])->map(fn ($m) => $m * $mag)->first(fn ($v) => $v >= $raw))
                    @php($top = $step * 4)
                    @php($fmt = fn ($v) => $v >= 1000000 ? rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.').'M' : number_format($v / 1000).'k')
                    @php($n = count($charts['disbursements']))
                    @php($w = 50 + $n * 80)
                    <svg viewBox="0 0 {{ $w }} 300" class="w-full" role="img" aria-label="{{ __('Disbursed principal – last 6 months') }}">
                        @foreach(range(0, 4) as $g)
                            @php($y = 260 - $g * 55)
                            <line x1="44" x2="{{ $w - 6 }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#e5e7eb" stroke-width="1" @if($g) stroke-dasharray="3 3" @endif/>
                            <text x="38" y="{{ $y + 3 }}" text-anchor="end" font-size="10" fill="#6b7280">{{ $fmt($step * $g) }}</text>
                        @endforeach
                        @foreach($charts['disbursements'] as $m => $v)
                            <line x1="{{ 50 + ($loop->index + 1) * 80 }}" x2="{{ 50 + ($loop->index + 1) * 80 }}" y1="40" y2="260" stroke="#eef0f2" stroke-width="1"/>
                        @endforeach
                        <line x1="44" x2="44" y1="40" y2="260" stroke="#e5e7eb" stroke-width="1"/>
                        @foreach($charts['disbursements'] as $m => $v)
                            @php($h = $v / $top * 220)
                            @php($x = 50 + $loop->index * 80)
                            <rect x="{{ $x + 12 }}" y="{{ 260 - $h }}" width="56" height="{{ max($h, $v > 0 ? 2 : 0) }}" rx="3" fill="{{ $loop->last ? '#166534' : '#15803d' }}"/>
                            <text x="{{ $x + 40 }}" y="{{ 253 - $h }}" text-anchor="middle" font-size="11" font-weight="600" fill="#111827">{{ $fmt($v) }}</text>
                            <text x="{{ $x + 40 }}" y="282" text-anchor="middle" font-size="11" fill="#4b5563">{{ \Carbon\Carbon::parse($m.'-01')->format('M') }}{{ $loop->last ? ' (MTD)' : '' }}</text>
                        @endforeach
                    </svg>
                </x-card>
            @endif
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
        @foreach(['product' => 'Product performance', 'branch' => 'Branch performance'] as $key => $title)
            @if(!empty($performance[$key]) && $performance[$key]->isNotEmpty())
                <x-card :title="__($title)" :icon="$key === 'product' ? 'cube' : 'map'" :padding="false">
                    <x-table dense class="pb-2">
                        <x-slot:head><x-th>{{ ucfirst($key) }}</x-th><x-th align="right">Loans</x-th><x-th align="right">Disbursed</x-th><x-th align="right">Outstanding</x-th><x-th align="right"><abbr title="{{ __('Accounts more than 30 days past due') }}" class="no-underline">PAR30</abbr></x-th></x-slot:head>
                        @foreach($performance[$key] as $r)
                            <tr><td class="font-medium">{{ $r->label }}</td><td class="text-right tabular">{{ $r->loans }}</td><td class="text-right"><x-money :amount="$r->disbursed"/></td>
                                <td class="text-right"><x-money :amount="$r->outstanding"/></td><td class="text-right">@if($r->par)<x-badge tone="red">{{ $r->par }}</x-badge>@else 0 @endif</td></tr>
                        @endforeach
                    </x-table>
                </x-card>
            @endif
        @endforeach
        </div>
    @endif
</div>
