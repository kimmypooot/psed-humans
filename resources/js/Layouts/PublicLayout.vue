<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useBranding } from '@/Composables/useBranding';

/**
 * The shell of the public pages (no account, no sidebar): a navy header with the two things an
 * agency does here, a main area, and a quiet footer. The submit page puts its hero in the
 * `hero` slot, which sits on the navy band behind the first card.
 */
const page = usePage();
const brand = useBranding();

const links = [
    { label: 'Submit HR Mechanism', href: '/', match: (url) => url === '/' || url.startsWith('/?') },
    { label: 'Track', href: '/track', match: (url) => url.startsWith('/track') },
];
const path = computed(() => page.url);
const imisUrl = computed(() => page.props.imisUrl);
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-white">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-primary">
            Skip to content
        </a>

        <header
            class="relative bg-csc-blue bg-cover bg-center text-white"
            style="background-image: linear-gradient(rgb(42 51 143 / 0.90), rgb(31 38 112 / 0.80)), url('/images/brand/cscbg_facade.jpeg')"
        >
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 py-3 sm:px-6">
                <Link href="/" class="flex min-w-0 items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none">
                    <img :src="brand.logo" alt="" class="size-10 shrink-0" />
                    <span class="min-w-0">
                        <span class="block text-base leading-tight font-bold tracking-wide">HUMANS</span>
                        <span class="block truncate text-[0.7rem] leading-tight text-white/70">CSC Regional Office VIII · HR Mechanisms</span>
                    </span>
                </Link>

                <nav aria-label="Public pages" class="flex items-center gap-1 text-sm font-medium">
                    <Link
                        v-for="l in links"
                        :key="l.href"
                        :href="l.href"
                        class="rounded-md px-3 py-1.5 transition-colors hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        :class="l.match(path) ? 'bg-white/15 text-white' : 'text-white/80'"
                        :aria-current="l.match(path) ? 'page' : undefined"
                    >
                        {{ l.label }}
                    </Link>
                </nav>
            </div>

            <slot name="hero" />
        </header>

        <main id="main" class="relative mx-auto w-full max-w-6xl flex-1 px-4 pb-12 sm:px-6">
            <slot />
        </main>

        <footer class="relative border-t border-border bg-surface">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-xs text-text-muted sm:px-6">
                <p>Civil Service Commission · Regional Office VIII · Policies and Systems Evaluation Division</p>
                <p>HUMANS v{{ page.props.app?.version }}</p>
            </div>
        </footer>
    </div>
</template>
