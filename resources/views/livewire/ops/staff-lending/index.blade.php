<div>
    <x-page-header :title="__('Staff lending')" :subtitle="__('Employer-linked financing repaid by salary deduction.')">
        <x-slot:actions><button wire:click="$set('showCreate', true)" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('Corporate client') }}</button></x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="border-b border-neutral-100 p-3"><x-input class="w-64" wire:model.live.debounce.400ms="search" :placeholder="__('Company, code, NTN…')"/></div>
        <x-table>
            <x-slot:head><x-th>{{ __('Company') }}</x-th><x-th>{{ __('NTN') }}</x-th><x-th>{{ __('Contact') }}</x-th><x-th>{{ __('Salary day') }}</x-th><x-th>{{ __('Staff') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
            @forelse($clients as $c)
                <tr><td><a href="{{ route('ops.staff-lending.show', $c) }}" wire:navigate class="link">{{ $c->company_name }}</a><div class="text-xs text-neutral-500">{{ $c->code }}</div></td>
                    <td class="text-xs">{{ $c->ntn }}</td><td class="text-xs">{{ $c->contact_person }}<div class="text-neutral-500">{{ $c->contact_phone }}</div></td>
                    <td>{{ $c->salary_day }}</td><td>{{ $c->staff_count }}</td><td><x-status :value="$c->status"/></td></tr>
            @empty
                <x-empty :colspan="6" :title="__('No corporate clients')" :description="__('Add an employer to register its staff and salary data for payroll-backed financing.')"/>
            @endforelse
        </x-table>
        {{ $clients->links() }}
    </x-card>
    <x-modal show="showCreate" :title="__('New corporate client')">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input class="sm:col-span-2" :label="__('Company name')" wire:model="form.company_name"/>
            <x-input :label="__('Registration no')" wire:model="form.registration_no"/><x-input :label="__('NTN')" wire:model="form.ntn"/>
            <x-input class="sm:col-span-2" :label="__('Address')" wire:model="form.address"/>
            <x-input :label="__('Contact person')" wire:model="form.contact_person"/><x-input :label="__('Contact phone')" wire:model="form.contact_phone"/>
            <x-input :label="__('Salary day of month')" type="number" wire:model="form.salary_day"/>
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary" wire:click="save">{{ __('Create') }}</button></x-slot:footer>
    </x-modal>
</div>
