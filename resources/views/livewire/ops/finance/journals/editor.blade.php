<div>
    <x-page-header :title="$journal ? 'Edit '.$journal->voucher_no : 'New journal voucher'" subtitle="Manual vouchers are posted only after approval by a different user (maker-checker)." :back="route('ops.finance.journals.index')"/>
    @if($journal?->rejection_reason)<x-alert tone="red" class="mb-4" title="Returned / rejected">{{ $journal->rejection_reason }}</x-alert>@endif
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <form wire:submit="save(false)" class="space-y-6">
        <x-card title="Header">
            <div class="grid gap-4 sm:grid-cols-4">
                <x-input type="date" label="Voucher date" wire:model="voucher_date" required/>
                <x-select label="Branch" wire:model="branch_id" placeholder="Head office / none" :options="$branches"/>
                <x-textarea label="Narration" wire:model="narration" rows="2" class="sm:col-span-2" required/>
            </div>
        </x-card>
        <x-card title="Lines" :padding="false">
            <x-slot:actions><button type="button" wire:click="addLine" class="btn-secondary btn-sm"><x-icon name="plus" class="h-4 w-4"/>Add line</button></x-slot:actions>
            <div class="relative overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-64">Account</th><th class="min-w-48">Description</th><th>Cost center</th><th>Branch</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th></th></tr></thead>
                    <tbody>
                    @foreach($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}">
                            <td><select wire:model="lines.{{ $i }}.account_id" class="field-input"><option value="">Select account</option>@foreach($accounts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></td>
                            <td><input wire:model="lines.{{ $i }}.description" class="field-input"></td>
                            <td><select wire:model="lines.{{ $i }}.cost_center_id" class="field-input"><option value="">—</option>@foreach($costCenters as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></td>
                            <td><select wire:model="lines.{{ $i }}.branch_id" class="field-input"><option value="">—</option>@foreach($branches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></td>
                            <td><input wire:model.live.debounce.500ms="lines.{{ $i }}.debit" inputmode="decimal" class="field-input w-32 text-right tabular"></td>
                            <td><input wire:model.live.debounce.500ms="lines.{{ $i }}.credit" inputmode="decimal" class="field-input w-32 text-right tabular"></td>
                            <td>@if(count($lines) > 2)<button type="button" wire:click="removeLine({{ $i }})" class="text-red-600 hover:underline text-xs">Remove</button>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="bg-neutral-50 font-semibold">
                        <tr><td colspan="4" class="px-4 py-3 text-right">Totals</td>
                            <td class="px-4 py-3 text-right"><x-money :amount="$totalDebit"/></td><td class="px-4 py-3 text-right"><x-money :amount="$totalCredit"/></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="px-5 py-3">
                @if(\App\Support\Money::isZero($difference) && \App\Support\Money::isPositive($totalDebit))
                    <x-badge tone="green">Balanced</x-badge>
                @else
                    <x-badge tone="red">Out of balance by {{ \App\Support\Money::format($difference) }}</x-badge>
                @endif
            </div>
        </x-card>
        <x-card title="Supporting documents">
            <input type="file" wire:model="attachments" multiple accept=".pdf,image/*" class="text-sm">
            <div wire:loading wire:target="attachments" class="text-xs text-neutral-500">Uploading…</div>
            @error('attachments.*')<p class="field-error">{{ $message }}</p>@enderror
        </x-card>
        <div class="flex justify-end gap-2">
            <button type="submit" class="btn-secondary" wire:loading.attr="disabled">Save draft</button>
            <button type="button" wire:click="save(true)" class="btn-primary" wire:loading.attr="disabled">Save & submit for approval</button>
        </div>
    </form>
</div>
