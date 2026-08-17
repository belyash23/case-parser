<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import type { PaginationLink } from '@/types';

defineProps<{ links: PaginationLink[] }>();

const paginationLabel = (label: string): string =>
    label
        .replaceAll('&laquo;', '')
        .replaceAll('&raquo;', '')
        .replaceAll('&lsaquo;', '')
        .replaceAll('&rsaquo;', '')
        .replace('Previous', 'Назад')
        .replace('Next', 'Вперёд')
        .trim();
</script>

<template>
    <nav class="flex flex-wrap gap-2" aria-label="Пагинация">
        <template v-for="link in links" :key="link.label">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                :class="[
                    'rounded-lg border px-3 py-2 text-sm transition',
                    link.active
                        ? 'border-teal bg-teal text-white'
                        : 'bg-white hover:border-teal hover:bg-mint/40',
                ]"
            >
                <span>{{ paginationLabel(link.label) }}</span>
            </Link>
            <span
                v-else
                class="rounded-lg border bg-white/50 px-3 py-2 text-sm text-slate-400"
            >
                {{ paginationLabel(link.label) }}
            </span>
        </template>
    </nav>
</template>
