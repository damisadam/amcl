<div>
    <x-page-header :title="__('Organization')" :subtitle="__('Region → Area → Branch hierarchy, departments, employees and branch-level product & fund access for this institution.')"/>
    <x-tabs :tabs="['hierarchy' => __('Branch hierarchy'), 'departments' => __('Departments'), 'employees' => __('Employees'), 'products' => __('Office loan products'), 'funds' => __('Office access to funds')]" :active="$tab"/>

    @if($tab === 'hierarchy')
        <div class="mb-3 flex justify-end"><button wire:click="newNode('region')" class="btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4"/>{{ __('Region') }}</button></div>
        <div class="space-y-3">
            @forelse($regions as $r)
                <x-card :padding="false">
                    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
                        <div><span class="font-semibold">{{ $r->name }}</span> <span class="text-xs text-neutral-500">{{ $r->code }}</span> <x-status :value="$r->status"/></div>
                        <div class="flex gap-1"><button wire:click="editNode('region', {{ $r->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button><button wire:click="newNode('area', {{ $r->id }})" class="btn-secondary btn-sm">+ {{ __('Area') }}</button></div>
                    </div>
                    @foreach($r->areas as $a)
                        <div class="border-b border-neutral-50 px-4 py-2 pl-8 last:border-0">
                            <div class="flex items-center justify-between"><div class="text-sm"><span class="font-medium">{{ $a->name }}</span> <span class="text-xs text-neutral-500">{{ $a->code }}</span> <x-status :value="$a->status"/></div>
                                <div class="flex gap-1"><button wire:click="editNode('area', {{ $a->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button><button wire:click="newNode('branch', {{ $a->id }})" class="btn-secondary btn-sm">+ {{ __('Branch') }}</button></div></div>
                            <ul class="mt-1 space-y-1 pl-4">
                                @foreach($a->branches as $b)
                                    <li class="flex items-center justify-between text-sm"><span>{{ $b->name }} <span class="text-xs text-neutral-500">{{ $b->code }} · {{ $b->city }} · {{ $b->products_count }} {{ __('products') }}</span>
                                        @if($b->is_head_office)<x-badge tone="black">{{ __('Head office') }}</x-badge>@endif <x-status :value="$b->status"/></span>
                                        <button wire:click="editNode('branch', {{ $b->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </x-card>
            @empty
                <x-card><x-empty :title="__('No regions yet')"/></x-card>
            @endforelse
        </div>
    @elseif($tab === 'departments')
        <x-card :padding="false" :title="__('Departments')">
            <x-slot:actions><button wire:click="newNode('department')" class="btn-primary btn-sm">+ {{ __('Department') }}</button></x-slot:actions>
            <x-table><x-slot:head><x-th>{{ __('Code') }}</x-th><x-th>{{ __('Name') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($departments as $d)<tr><td class="text-xs">{{ $d->code }}</td><td>{{ $d->name }}</td><td class="text-right"><button wire:click="editNode('department', {{ $d->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></td></tr>
                @empty<x-empty :colspan="3" :title="__('No departments')"/>@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'employees')
        <x-card :padding="false">
            <div class="flex gap-2 border-b border-neutral-100 p-4"><input type="search" wire:model.live.debounce.400ms="empSearch" class="field-input sm:max-w-xs" placeholder="{{ __('Search code or name') }}">
                <button wire:click="newEmployee" class="btn-primary btn-sm ml-auto">+ {{ __('Employee') }}</button></div>
            <x-table><x-slot:head><x-th>{{ __('Code') }}</x-th><x-th>{{ __('Name') }}</x-th><x-th>{{ __('Designation') }}</x-th><x-th>{{ __('Branch / dept') }}</x-th><x-th>{{ __('Reports to') }}</x-th><x-th align="right">{{ __('Gross salary') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($employees as $e)
                    <tr><td class="text-xs">{{ $e->employee_code }}</td><td>{{ $e->name }}</td><td class="text-xs">{{ $e->designation }}</td><td class="text-xs">{{ $e->branch?->name }} · {{ $e->department?->name }}</td><td class="text-xs">{{ $e->reportsTo?->name }}</td>
                        <td class="text-right"><x-money :amount="\App\Support\Money::add($e->basic_salary, $e->allowances)"/></td><td><x-status :value="$e->status"/></td><td class="text-right"><button wire:click="editEmployee({{ $e->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></td></tr>
                @empty<x-empty :colspan="8" :title="__('No employees')"/>@endforelse
            </x-table>
            {{ $employees->links() }}
        </x-card>
    @else
        @php($isProducts = $tab === 'products')
        <x-card :padding="false" :title="$isProducts ? __('Products available per branch') : __('Funding pools each branch may draw from')"
            :subtitle="$isProducts ? __('Controls regional product rollout (LMS §9.11).') : __('Financial control linking funds to offices (LMS §9.12).')">
            <div class="relative overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>{{ __('Branch') }}</th>@foreach($isProducts ? $products : $funds as $col)<th class="text-center!">{{ $isProducts ? $col->name : $col->name }}</th>@endforeach</tr></thead>
                    <tbody class="divide-y divide-neutral-100">
                        @forelse($branches as $b)
                            <tr><td class="whitespace-nowrap font-medium">{{ $b->name }}</td>
                                @foreach($isProducts ? $products : $funds as $col)
                                    @php($on = isset(($isProducts ? $branchProducts : $branchFunds)[$b->id.'-'.$col->id]))
                                    <td class="text-center"><input type="checkbox" @checked($on) wire:click="{{ $isProducts ? 'toggleProduct' : 'toggleFund' }}({{ $b->id }}, {{ $col->id }})" class="rounded border-neutral-300 text-brand-700"></td>
                                @endforeach
                            </tr>
                        @empty<tr><td>{{ __('No branches') }}</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            @if(($isProducts ? $products : $funds)->isEmpty())<x-empty :title="$isProducts ? __('No products configured yet') : __('No funding sources configured yet')"/>@endif
        </x-card>
    @endif

    <x-modal show="showNode" :title="($nodeId ? __('Edit') : __('New')).' '.__(ucfirst($nodeType))">
        <form wire:submit="saveNode" class="grid gap-3 sm:grid-cols-2">
            <x-input :label="__('Code')" wire:model="node.code" required/>
            <x-input :label="__('Name')" wire:model="node.name" required/>
            @if($nodeType === 'area')<x-select class="sm:col-span-2" :label="__('Region')" wire:model="node.parent_id" :options="$regionOptions->all()" :placeholder="__('Select…')" required/>@endif
            @if($nodeType === 'branch')
                <x-select class="sm:col-span-2" :label="__('Area')" wire:model="node.parent_id" :options="$areaOptions->all()" :placeholder="__('Select…')" required/>
                <x-input :label="__('City')" wire:model="node.city"/><x-input :label="__('Phone')" wire:model="node.phone"/>
                <x-input class="sm:col-span-2" :label="__('Address')" wire:model="node.address"/>
                <x-checkbox wire:model="node.is_head_office" :label="__('Head office')"/>@error('node.is_head_office')<p class="field-error">{{ $message }}</p>@enderror
            @endif
            @if($nodeType !== 'department')<x-select :label="__('Status')" wire:model="node.status" :options="['active' => __('Active'), 'inactive' => __('Inactive')]"/>@endif
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>

    <x-modal show="showEmployee" :title="$employeeId ? __('Edit employee') : __('New employee')" max-width="2xl">
        <form wire:submit="saveEmployee" class="grid gap-3 sm:grid-cols-3">
            <x-input :label="__('Employee code')" wire:model="emp.employee_code" required/>
            <x-input class="sm:col-span-2" :label="__('Name')" wire:model="emp.name" required/>
            <x-input :label="__('CNIC')" wire:model="emp.cnic" placeholder="35202-1234567-1"/>
            <x-input :label="__('Designation')" wire:model="emp.designation"/>
            <x-input :label="__('Joined on')" type="date" wire:model="emp.joined_on"/>
            <x-select :label="__('Branch')" wire:model="emp.branch_id" :options="$branchOptions->all()" :placeholder="__('—')"/>
            <x-select :label="__('Department')" wire:model="emp.department_id" :options="$departmentOptions->all()" :placeholder="__('—')"/>
            <x-select :label="__('Reports to')" wire:model="emp.reports_to_id" :options="$employeeOptions->all()" :placeholder="__('—')"/>
            <x-input :label="__('Phone')" wire:model="emp.phone"/><x-input :label="__('Email')" wire:model="emp.email"/>
            <x-select :label="__('Status')" wire:model="emp.status" :options="['active' => __('Active'), 'inactive' => __('Inactive'), 'terminated' => __('Terminated')]"/>
            <x-input :label="__('Basic salary (PKR)')" type="number" step="0.01" wire:model="emp.basic_salary"/>
            <x-input :label="__('Allowances (PKR)')" type="number" step="0.01" wire:model="emp.allowances"/>
            <x-input :label="__('Salary IBAN')" wire:model="emp.bank_iban"/>
            <div class="flex justify-end gap-2 sm:col-span-3"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</div>
