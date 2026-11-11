<div x-data="{ toasts: [], add(t){ const id = Date.now()+Math.random(); this.toasts.push({id, ...t}); setTimeout(() => this.toasts = this.toasts.filter(x => x.id !== id), t.tone === 'red' ? 8000 : 4500) } }"
     x-on:toast.window="add($event.detail[0] ?? $event.detail)"
     x-init="@if(session('success')) add({message: @js(session('success')), tone: 'green'}); @endif @if(session('error')) add({message: @js(session('error')), tone: 'red'}); @endif @if(session('info')) add({message: @js(session('info')), tone: 'blue'}); @endif"
     class="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-3 sm:items-end sm:right-4 sm:left-auto">
    <template x-for="t in toasts" :key="t.id">
        <div x-transition class="pointer-events-auto w-full max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"
             :class="{ 'bg-brand-800 text-white border-brand-900': t.tone === 'green', 'bg-red-600 text-white border-red-700': t.tone === 'red', 'bg-white text-ink border-neutral-200': !['green','red'].includes(t.tone) }">
            <p x-text="t.message"></p>
        </div>
    </template>
</div>
