<div>
    <x-page-header :title="__('New vendor')" :subtitle="__('New vendors start as Pending and must be approved before they can receive disbursements or payments.')" :back="route('ops.vendors.index')"/>
    <form wire:submit="save">
        <x-card>@include('livewire.ops.vendors._form')</x-card>
        @error('action')<x-alert tone="red" class="mt-4">{{ $message }}</x-alert>@enderror
        <div class="mt-4 flex justify-end gap-2"><a href="{{ route('ops.vendors.index') }}" wire:navigate class="btn-secondary">{{ __('Cancel') }}</a><button class="btn-primary">{{ __('Create vendor') }}</button></div>
    </form>
</div>
