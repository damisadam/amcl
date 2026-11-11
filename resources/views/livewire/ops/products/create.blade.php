<div>
    <x-page-header :title="__('New financing product')" :back="route('ops.products.index')"/>
    <form wire:submit="save" class="space-y-5">
        <x-card :title="__('Product definition')">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-input :label="__('Code')" wire:model="product.code" required/>
                <x-input :label="__('Name (English)')" wire:model="product.name" required/>
                <x-input :label="__('Name (Urdu)')" wire:model="product.name_ur" class="font-urdu" dir="rtl"/>
                <x-select :label="__('Category')" wire:model="product.category" :options="\App\Enums\ProductCategory::options()"/>
                <x-select :label="__('Shariah structure')" wire:model="product.shariah_structure" :options="\App\Enums\ShariahStructure::options()"/>
                <x-select :label="__('Parent product (for sub-product)')" wire:model="product.parent_id" :placeholder="__('None – top-level')" :options="$parents"/>
                <x-select :label="__('Loan category')" wire:model="product.loan_category_id" placeholder="—" :options="$categories"/>
                <x-select :label="__('Client segment')" wire:model="product.client_segment" :options="['individual' => 'Individual', 'sme' => 'SME / business', 'staff' => 'Corporate staff']"/>
                <x-input :label="__('Shariah board approval ref')" wire:model="product.shariah_approval_ref"/>
                <x-textarea class="sm:col-span-3" :label="__('Description (English)')" wire:model="product.description"/>
                <x-textarea class="sm:col-span-3" :label="__('Description (Urdu)')" wire:model="product.description_ur" dir="rtl"/>
            </div>
            <div class="mt-3 flex flex-wrap gap-5">
                <x-checkbox wire:model="product.shariah_board_approved" :label="__('Approved by Shariah board')"/>
                <x-checkbox wire:model="product.is_staff_product" :label="__('Staff / payroll product')"/>
                <x-checkbox wire:model="product.show_in_catalog" :label="__('Show in customer catalogue')"/>
            </div>
        </x-card>
        @include('livewire.ops.products._terms')
        <x-card :title="__('Available at branches')">
            <div class="grid gap-2 sm:grid-cols-3">@foreach($branches as $b)<x-checkbox wire:model="branchIds" value="{{ $b->id }}" label="{{ $b->name }}"/>@endforeach</div>
        </x-card>
        @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
        @if($errors->any() && ! $errors->has('action'))<x-alert tone="red">{{ __('Please correct the highlighted fields.') }}</x-alert>@endif
        <div class="flex justify-end gap-2"><a href="{{ route('ops.products.index') }}" wire:navigate class="btn-secondary">{{ __('Cancel') }}</a><button class="btn-primary" wire:loading.attr="disabled">{{ __('Create product') }}</button></div>
    </form>
</div>
