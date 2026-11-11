@props(['dense' => false, 'inset' => false])
{{-- inset: table sits in its own bordered box inside the card padding (refs: posting-rules, platform-overview). --}}
<div {{ $attributes->merge(['class' => 'relative overflow-x-auto'.($inset ? ' mx-4 mb-4 rounded-lg border border-neutral-200' : '')]) }}>
    <table @class(['table-base', 'table-dense' => $dense])>
        @isset($head)<thead><tr>{{ $head }}</tr></thead>@endisset
        <tbody class="divide-y divide-neutral-100 bg-white">{{ $slot }}</tbody>
        @isset($foot)<tfoot class="bg-neutral-50 font-semibold">{{ $foot }}</tfoot>@endisset
    </table>
</div>
