@props(['value'])
@if($value instanceof \BackedEnum)
    <x-badge :tone="method_exists($value, 'tone') ? $value->tone() : 'gray'" :attributes="$attributes">{{ __(method_exists($value, 'label') ? $value->label() : $value->value) }}</x-badge>
@elseif($value)
    @php($tone = match((string) $value) { 'active','paid','succeeded','passed','posted','approved','verified','matched','completed','clear','signed','wet_ink_received','processed','resolved','sent','success','open_ok','kept','disbursed','reclassified' => 'green',
        'failed','rejected','overdue','declined','adverse','confirmed_match','error','broken','high','timeout','timed_out','duplicate','exception','amount_mismatch','missing_external','missing_internal','duplicate_reference','negative' => 'red',
        'pending','pending_approval','pending_review','partially_paid','processing','in_review','scheduled','draft','open','medium','potential_match','manual_review','wet_ink_pending','follow_up','in_progress','refer' => 'amber',
        default => 'gray' })
    <x-badge :tone="$tone" :attributes="$attributes">{{ __(\Illuminate\Support\Str::headline((string) $value)) }}</x-badge>
@else
    <span class="text-neutral-400">—</span>
@endif
