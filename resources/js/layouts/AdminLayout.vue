<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { index as casesIndex } from '@/actions/App/Http/Controllers/Admin/CaseController';
import DashboardController from '@/actions/App/Http/Controllers/Admin/DashboardController';
import { index as parserIndex } from '@/actions/App/Http/Controllers/Admin/ParserController';
import { edit as settingsEdit } from '@/actions/App/Http/Controllers/Admin/ParserSettingController';
import { index as reportsIndex } from '@/actions/App/Http/Controllers/Admin/ReportController';
import { logout } from '@/routes';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const flash = computed(() => page.props.flash ?? {});
const navigation = [
    { label: 'Обзор', href: DashboardController() },
    { label: 'Парсер', href: parserIndex() },
    { label: 'Дела и покрытие', href: casesIndex() },
    { label: 'Отчёты', href: reportsIndex() },
    { label: 'Настройки', href: settingsEdit() },
];
const isCurrent = (url: string): boolean => {
    const currentUrl = page.url.split('?')[0];

    return url === '/admin'
        ? currentUrl === url
        : currentUrl === url || currentUrl.startsWith(`${url}/`);
};
</script>

<template>
    <div class="min-h-screen lg:grid lg:grid-cols-[250px_1fr]">
        <aside
            class="border-b border-white/10 bg-ink px-5 py-5 text-white lg:min-h-screen lg:border-r lg:border-b-0 lg:px-6 lg:py-8"
        >
            <div class="flex items-center justify-between gap-4 lg:block">
                <div
                    class="text-xs font-semibold tracking-[0.22em] text-emerald-300 uppercase"
                >
                    Case Parser
                </div>
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    class="text-sm text-slate-300 hover:text-white lg:hidden"
                >
                    Выход
                </Link>
            </div>
            <nav
                class="mt-6 flex gap-2 overflow-x-auto pb-1 lg:mt-10 lg:flex-col lg:overflow-visible"
            >
                <Link
                    v-for="item in navigation"
                    :key="item.label"
                    :href="item.href"
                    :aria-current="
                        isCurrent(item.href.url) ? 'page' : undefined
                    "
                    :class="[
                        'shrink-0 rounded-xl px-4 py-3 text-sm font-medium transition',
                        isCurrent(item.href.url)
                            ? 'bg-white text-ink shadow-sm'
                            : 'text-slate-300 hover:bg-white/10 hover:text-white',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </nav>
            <div
                class="mt-10 hidden rounded-2xl bg-white/6 p-4 text-sm text-slate-300 lg:block"
            >
                <div class="font-semibold text-white">
                    {{ page.props.auth.user?.name }}
                </div>
                <Link
                    :href="logout()"
                    method="post"
                    as="button"
                    class="mt-4 text-emerald-300 hover:text-emerald-200"
                >
                    Выход
                </Link>
            </div>
        </aside>

        <main class="min-w-0 px-4 py-6 sm:px-7 lg:px-10 lg:py-9">
            <div
                v-if="flash.success"
                class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-900"
            >
                {{ flash.success }}
            </div>
            <div
                v-if="flash.error"
                class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900"
            >
                {{ flash.error }}
            </div>
            <slot />
        </main>
    </div>
</template>
