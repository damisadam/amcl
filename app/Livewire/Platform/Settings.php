<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Tenant;
use App\Services\Platform\PlatformSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.platform')]
class Settings extends Component
{
    use HandlesActions;

    public string $platform_name = '';

    public array $default_features = [];

    public string $demo_banner_text = '';

    public string $maintenance_notice = '';

    public function mount(): void
    {
        $this->need('platform.settings.manage');
        foreach (PlatformSettings::all() as $k => $v) {
            $this->{$k} = $v ?? (is_array($this->{$k}) ? [] : '');
        }
    }

    public function save(): void
    {
        $this->need('platform.settings.manage');
        $this->validate([
            'platform_name' => 'required|string|max:120', 'default_features' => 'array', 'default_features.*' => 'in:'.implode(',', array_keys(Tenant::FEATURES)),
            'demo_banner_text' => 'nullable|string|max:300', 'maintenance_notice' => 'nullable|string|max:300',
        ]);
        $this->attempt(function () {
            foreach (array_keys(PlatformSettings::defaults()) as $k) {
                PlatformSettings::set($k, $this->{$k} ?? '', auth()->id());
            }
        }, 'Platform settings saved.');
    }

    public function render()
    {
        return view('livewire.platform.settings');
    }
}
