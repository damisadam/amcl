<div>
    <x-page-header :title="$corporate->company_name" :subtitle="$corporate->code.' · '.__('salary day').' '.$corporate->salary_day" :back="route('ops.staff-lending.index')"/>
    <x-tabs :tabs="['staff' => __('Staff registry'), 'salaries' => __('Salary records'), 'reconciliation' => __('Deduction reconciliation'), 'details' => __('Company details')]" :active="$tab"/>

    @if(in_array($tab, ['salaries', 'reconciliation']))
        <div class="mb-4 w-48"><x-input :label="__('Period')" type="month" wire:model.live="period"/></div>
    @endif

    @if($tab === 'staff')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :padding="false" class="lg:col-span-2">
                <x-table><x-slot:head><x-th>{{ __('Emp no') }}</x-th><x-th>{{ __('Name') }}</x-th><x-th>{{ __('Designation') }}</x-th><x-th align="right">{{ __('Salary') }}</x-th><x-th>{{ __('Customer CIF') }}</x-th><x-th></x-th></x-slot:head>
                    @forelse($staff as $s)
                        <tr wire:key="s{{ $s->id }}"><td>{{ $s->employee_no }}</td><td>{{ $s->name }}<div class="text-xs text-neutral-500">{{ $s->cnic ? substr($s->cnic, 0, 5).'-*******-'.substr($s->cnic, -1) : '' }}</div></td>
                            <td class="text-xs">{{ $s->designation }} {{ $s->salary_band ? '· '.$s->salary_band : '' }}</td><td class="text-right"><x-money :amount="$s->monthly_salary" :currency="false"/></td>
                            <td class="text-xs">{{ $s->customer?->cif_no ?? '—' }}</td><td class="text-right"><button wire:click="editStaff({{ $s->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></td></tr>
                    @empty<x-empty :colspan="6" :title="__('No staff registered')"/>@endforelse
                </x-table>
            </x-card>
            <div class="space-y-4">
                <x-card :title="$staffForm['id'] ? __('Edit staff') : __('Add staff')">
                    <form wire:submit="saveStaff" class="space-y-3">
                        <x-input :label="__('Employee no')" wire:model="staffForm.employee_no"/><x-input :label="__('Name')" wire:model="staffForm.name"/>
                        <x-input :label="__('CNIC')" wire:model="staffForm.cnic" placeholder="12345-1234567-1"/><x-input :label="__('Designation')" wire:model="staffForm.designation"/>
                        <div class="grid grid-cols-2 gap-2"><x-input :label="__('Joined')" type="date" wire:model="staffForm.joined_on"/><x-input :label="__('Band')" wire:model="staffForm.salary_band"/></div>
                        <x-input :label="__('Monthly salary')" type="number" step="0.01" wire:model="staffForm.monthly_salary"/>
                        @error('action')<p class="field-error">{{ $message }}</p>@enderror
                        <button class="btn-primary w-full">{{ __('Save') }}</button>
                    </form>
                </x-card>
                <x-card :title="__('Link staff to customer CIF')">
                    <form wire:submit="link" class="space-y-3">
                        <x-select :label="__('Staff')" wire:model="linkStaffId" placeholder="—" :options="$staff->pluck('name', 'id')->all()"/>
                        <x-input :label="__('Customer CIF or CNIC')" wire:model="linkRef"/>
                        <button class="btn-secondary w-full">{{ __('Link') }}</button>
                    </form>
                </x-card>
            </div>
        </div>
    @elseif($tab === 'salaries')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :padding="false" class="lg:col-span-2">
                <x-table><x-slot:head><x-th>{{ __('Staff') }}</x-th><x-th align="right">{{ __('Gross') }}</x-th><x-th align="right">{{ __('Loan deduction') }}</x-th><x-th align="right">{{ __('Net') }}</x-th><x-th>{{ __('Source') }}</x-th></x-slot:head>
                    @forelse($salaries as $r)<tr><td>{{ $r->staff->name }} <span class="text-xs text-neutral-500">{{ $r->staff->employee_no }}</span></td><td class="text-right"><x-money :amount="$r->gross" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$r->loan_deduction" :currency="false"/></td><td class="text-right"><x-money :amount="$r->net" :currency="false"/></td><td class="text-xs">{{ $r->source }}</td></tr>
                    @empty<x-empty :colspan="5" :title="__('No salary data for this period')"/>@endforelse
                </x-table>
            </x-card>
            <div class="space-y-4">
                <x-card :title="__('Record salary')">
                    <form wire:submit="saveSalary" class="space-y-3">
                        <x-select :label="__('Staff')" wire:model="salary.staff_id" placeholder="—" :options="$staff->pluck('name', 'id')->all()"/>
                        <x-input :label="__('Gross salary')" type="number" step="0.01" wire:model="salary.gross"/>
                        <x-input :label="__('Loan deduction')" type="number" step="0.01" wire:model="salary.deduction" :hint="__('Leave blank to use instalments due in the period.')"/>
                        @error('action')<p class="field-error">{{ $message }}</p>@enderror
                        <button class="btn-primary w-full">{{ __('Save') }}</button>
                    </form>
                </x-card>
                <x-card :title="__('Import CSV')">
                    <x-textarea wire:model="csv" rows="5" placeholder="employee_no,period,gross,loan_deduction&#10;E001,{{ now()->format('Y-m') }},85000,"/>
                    @foreach($importErrors as $e)<p class="field-error">{{ $e }}</p>@endforeach
                    <button wire:click="import" class="btn-secondary mt-2 w-full">{{ __('Import') }}</button>
                </x-card>
            </div>
        </div>
    @elseif($tab === 'reconciliation')
        <x-alert tone="blue" class="mb-4">{{ __('Compares payroll loan deductions with instalments due in the period. Posting a deduction records a "salary deduction" repayment on the staff member\'s financing.') }}</x-alert>
        <x-card :padding="false">
            <x-table><x-slot:head><x-th>{{ __('Staff') }}</x-th><x-th>{{ __('CIF') }}</x-th><x-th align="right">{{ __('Due in period') }}</x-th><x-th align="right">{{ __('Payroll deduction') }}</x-th><x-th align="right">{{ __('Posted') }}</x-th><x-th>{{ __('Result') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($recon ?? [] as $r)
                    @php($diff = \App\Support\Money::sub($r['deduction'], $r['due']))
                    <tr><td>{{ $r['staff']->name }}</td><td class="text-xs">{{ $r['staff']->customer?->cif_no ?? '—' }}</td>
                        <td class="text-right"><x-money :amount="$r['due']" :currency="false"/></td><td class="text-right"><x-money :amount="$r['deduction']" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$r['recovered']" :currency="false"/></td>
                        <td>@if(! $r['has_salary'])<x-badge>{{ __('No salary data') }}</x-badge>@elseif(\App\Support\Money::isZero($diff))<x-badge tone="green">{{ __('Matched') }}</x-badge>@else<x-badge tone="amber">{{ __('Difference') }} {{ \App\Support\Money::format($diff, false) }}</x-badge>@endif</td>
                        <td class="text-right">@if(\App\Support\Money::isPositive($r['pending']) && $r['staff']->customer_id)@can('repayments.record')<button wire:click="postDeduction({{ $r['staff']->id }})" wire:confirm="{{ __('Post this deduction as a repayment?') }}" class="btn-primary btn-sm">{{ __('Post') }} {{ \App\Support\Money::format($r['pending'], false) }}</button>@endcan @endif</td></tr>
                @empty<x-empty :colspan="7" :title="__('No staff')"/>@endforelse
            </x-table>
        </x-card>
    @else
        <x-card :title="__('Company details')">
            <form wire:submit="saveInfo" class="grid gap-3 sm:grid-cols-2">
                <x-input class="sm:col-span-2" :label="__('Company name')" wire:model="info.company_name"/>
                <x-input :label="__('Registration no')" wire:model="info.registration_no"/><x-input :label="__('NTN')" wire:model="info.ntn"/>
                <x-input class="sm:col-span-2" :label="__('Address')" wire:model="info.address"/>
                <x-input :label="__('Contact person')" wire:model="info.contact_person"/><x-input :label="__('Contact phone')" wire:model="info.contact_phone"/>
                <x-input :label="__('Salary day')" type="number" wire:model="info.salary_day"/><x-select :label="__('Status')" wire:model="info.status" :options="['active' => 'Active', 'inactive' => 'Inactive']"/>
                <div class="sm:col-span-2"><button class="btn-primary">{{ __('Save') }}</button></div>
            </form>
        </x-card>
    @endif
</div>
