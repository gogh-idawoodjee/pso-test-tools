{{-- A small "back to top" button that appears once the page is scrolled well past the first screen. --}}
<div
    x-data="{ shown: false, check() { this.shown = window.scrollY > 600 } }"
    x-init="check()"
    x-on:scroll.window.passive="check()"
    x-show="shown"
    x-cloak
    x-transition.opacity.duration.200ms
    class="fixed bottom-5 right-5 z-40 print:hidden"
>
    <button
        type="button"
        x-on:click="window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })"
        title="Back to top"
        aria-label="Back to top"
        class="flex size-10 items-center justify-center rounded-full bg-white text-gray-600 shadow-md ring-1 ring-gray-950/10 transition hover:bg-gray-50 hover:text-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-gray-700"
    >
        <x-filament::icon icon="heroicon-m-arrow-up" class="size-5" />
    </button>
</div>
