<div>
    <x-page-header title="Platform settings" subtitle="Platform-wide defaults. Tenant business configuration is managed by each tenant administrator."/>
    <form wire:submit="save" class="space-y-6">
        <x-card title="General">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Platform name" wire:model="platform_name" required/>
                <x-textarea label="Demo banner text" wire:model="demo_banner_text"/>
                <x-textarea label="Maintenance notice" wire:model="maintenance_notice" hint="Leave empty for none."/>
            </div>
        </x-card>
        <x-card title="Default feature entitlements for new tenants">
            <div class="grid gap-2 sm:grid-cols-2">@foreach(\App\Models\Tenant::FEATURES as $k => $label)<x-checkbox wire:model="default_features" value="{{ $k }}" :label="$label"/>@endforeach</div>
        </x-card>
        <div class="flex justify-end"><button class="btn-primary">Save settings</button></div>
    </form>
</div>
