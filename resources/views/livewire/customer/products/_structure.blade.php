@php($texts = [
    'murabaha' => __('Murabaha: we buy the asset from the vendor and sell it to you at a disclosed cost plus profit, payable in instalments. The price never increases after signing.'),
    'ijarah' => __('Ijarah: we own and lease the asset to you for a rental; ownership transfers to you at the end of the lease.'),
    'diminishing_musharakah' => __('Diminishing Musharakah: we jointly own the asset and you gradually buy our share until you own it fully.'),
    'musharakah' => __('Musharakah: a partnership where profit is shared by an agreed ratio and loss by capital contribution.'),
    'mudarabah' => __('Mudarabah: one party provides capital, the other management; profit is shared by an agreed ratio.'),
    'salam' => __('Salam: full advance payment for goods delivered at a future date to agreed specifications.'),
    'hire_purchase' => __('Hire purchase: ownership transfers to you on completion of the scheduled payments.'),
])
<p class="mt-2 text-sm text-neutral-600">{{ $texts[$structure] ?? '' }}</p>
