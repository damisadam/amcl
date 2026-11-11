<div>
    <x-page-header title="Posting rules" subtitle="Which accounts each LMS / DFS / Wallet / AP event debits and credits. Changes apply to future postings only and are audited."/>
    <div class="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] 2xl:grid-cols-[19rem_minmax(0,1fr)]">
        <x-card title="Events" :padding="false" :divider="false">
            <nav class="mx-3 mt-2 max-h-[34rem] space-y-0.5 overflow-y-auto rounded-lg border border-neutral-200 p-1.5 text-sm [scrollbar-width:thin]">
                @foreach($events as $e)
                    <button wire:click="select('{{ $e }}')" @class(['flex w-full items-center justify-between gap-2 rounded-md border-l-4 px-3 py-1.5 text-left text-[14px] transition', 'border-brand-600 bg-brand-50 font-semibold text-brand-800' => $e === $event, 'border-transparent text-neutral-700 hover:bg-neutral-50' => $e !== $event]) @if($e === $event) aria-current="true" @endif>
                        <span class="truncate">{{ $e }}</span><span class="text-xs tabular {{ $e === $event ? 'text-brand-700' : 'text-neutral-400' }}" title="Vouchers posted with this event">{{ $usage[$e] ?? 0 }}</span></button>
                @endforeach
            </nav>
            @if($canEdit)
                <div class="flex gap-2 p-3"><x-input wire:model="newEvent" placeholder="custom_event" class="flex-1"/><button wire:click="createEvent" class="btn-secondary">Add</button></div>
            @endif
        </x-card>
        <div class="min-w-0">
            <x-card :title="$event" :divider="false" :subtitle="in_array($event, $system) ? 'System event – sides and amount keys are fixed so vouchers always balance; accounts can be re-mapped.' : 'Custom event – debit and credit sides must use the same amount keys.'" :padding="false">
                @error('action')<div class="p-4"><x-alert tone="red">{{ $message }}</x-alert></div>@enderror
                <div class="relative mx-4 mt-3 overflow-x-auto rounded-lg border border-neutral-200">
                    <table class="table-base [&_td]:px-2 [&_th]:px-2">
                        <thead><tr><th>#</th><th>Side</th><th>Account</th><th>Amount key</th><th>Description</th><th>Active</th><th></th></tr></thead>
                        <tbody>
                        @foreach($lines as $i => $l)
                            <tr wire:key="rule-{{ $event }}-{{ $i }}">
                                <td class="tabular">{{ $i + 1 }}</td>
                                <td><select wire:model="lines.{{ $i }}.side" class="field-input w-24" @disabled(! $canEdit) aria-label="Side"><option value="debit">Debit</option><option value="credit">Credit</option></select></td>
                                <td><select wire:model="lines.{{ $i }}.account_id" class="field-input w-56 2xl:w-72" @disabled(! $canEdit) aria-label="Account"><option value="">—</option>@foreach($accounts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></td>
                                <td><input wire:model="lines.{{ $i }}.amount_key" class="field-input w-32 font-mono text-[13px]" @disabled(! $canEdit) aria-label="Amount key"></td>
                                <td><input wire:model="lines.{{ $i }}.description" class="field-input w-36 2xl:w-44" @disabled(! $canEdit) aria-label="Description"></td>
                                <td><input type="checkbox" wire:model="lines.{{ $i }}.is_active" class="h-5 w-5 rounded border-neutral-300 text-brand-700 accent-brand-700" @disabled(! $canEdit) aria-label="Active"></td>
                                <td>@if($canEdit && ! in_array($event, $system))<button wire:click="removeLine({{ $i }})" class="text-xs text-red-600">Remove</button>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($canEdit)
                    <div class="flex justify-between px-5 py-4">
                        @unless(in_array($event, $system))<button wire:click="addLine" class="btn-secondary btn-sm">Add line</button>@else<span></span>@endunless
                        <button wire:click="save" class="btn-primary px-5"><x-icon name="save" class="h-4 w-4"/>Save rules</button>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</div>
