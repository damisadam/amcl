@if($this->demo())
    <x-select :label="__('Simulation scenario (demo only)')" wire:model="scenario" :options="$scenarios" :hint="__('Decides how the simulated provider responds.')"/>
@endif
