<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

import {
    cancel,
    finish,
    pause,
    resume,
    startInitial,
    startRegular,
} from '@/actions/App/Http/Controllers/Admin/ParserController';
import StatusBadge from '@/components/StatusBadge.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

interface Campaign {
    id: number;
    mode: string;
    status: string;
    window_from?: string;
    window_to?: string;
    requests_used: number;
    request_budget?: number;
    pending_work_count: number;
    running_work_count: number;
    completed_work_count: number;
    created_at: string;
}
interface WorkItem {
    id: number;
    work_type: string;
    status: string;
    target_date?: string;
    attempts: number;
    last_error?: string;
    court?: { name: string };
}
interface Region {
    id: number;
    sudrf_region_id: number;
    name: string;
}
interface Court {
    id: number;
    region_id?: number;
    name: string;
}

defineProps<{
    campaigns: Campaign[];
    workItems: WorkItem[];
    regions: Region[];
    courts: Court[];
}>();
const today = new Date().toISOString().slice(0, 10);
const initialForm = useForm({
    from: '2023-01-01',
    to: today,
    court_ids: [] as number[],
    region_ids: [] as number[],
    skip_directory_sync: false,
});
const regularForm = useForm({
    court_ids: [] as number[],
    region_ids: [] as number[],
    skip_directory_sync: false,
});
const act = (route: ReturnType<typeof pause>) =>
    router.visit(route, { preserveScroll: true });
let refreshTimer: number | undefined;
onMounted(() => {
    refreshTimer = window.setInterval(
        () => router.reload({ only: ['campaigns', 'workItems'] }),
        10000,
    );
});
onUnmounted(() => window.clearInterval(refreshTimer));
const campaignModeLabels: Record<string, string> = {
    initial: 'Разовый обход',
    regular: 'Регулярный обход',
};
const workTypeLabels: Record<string, string> = {
    calendar_day: 'День календаря',
    initial_month: 'Месяц разового обхода',
    case_card: 'Карточка дела',
    head_sync: 'Новые дела',
    backlog_drain: 'Накопившийся хвост',
    recheck: 'Повторная проверка',
};
const campaignModeLabel = (mode: string): string =>
    campaignModeLabels[mode] ?? 'Неизвестный режим';
const workTypeLabel = (type: string): string =>
    workTypeLabels[type] ?? 'Неизвестный тип';
</script>

<template>
    <Head title="Управление парсером" />
    <AdminLayout>
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Управление парсером</h1>
        </header>

        <section class="grid gap-6 xl:grid-cols-2">
            <form
                class="rounded-3xl border bg-white p-6"
                @submit.prevent="initialForm.submit(startInitial())"
            >
                <h2 class="text-lg font-semibold">Разовый обход</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium"
                        >С даты<input
                            v-model="initialForm.from"
                            type="date"
                            class="rounded-xl border px-3 py-2.5" /></label
                    ><label class="grid gap-2 text-sm font-medium"
                        >По дату<input
                            v-model="initialForm.to"
                            type="date"
                            class="rounded-xl border px-3 py-2.5"
                    /></label>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium"
                        >Регионы
                        <span class="font-normal text-slate-400"
                            >Ctrl для нескольких</span
                        ><select
                            v-model="initialForm.region_ids"
                            multiple
                            class="h-32 rounded-xl border px-3 py-2"
                        >
                            <option
                                v-for="region in regions"
                                :key="region.id"
                                :value="region.sudrf_region_id"
                            >
                                {{ region.name }}
                            </option>
                        </select></label
                    ><label class="grid gap-2 text-sm font-medium"
                        >Отдельные суды<select
                            v-model="initialForm.court_ids"
                            multiple
                            class="h-32 rounded-xl border px-3 py-2"
                        >
                            <option
                                v-for="court in courts"
                                :key="court.id"
                                :value="court.id"
                            >
                                {{ court.name }}
                            </option>
                        </select></label
                    >
                </div>
                <label class="mt-4 flex items-center gap-3 text-sm"
                    ><input
                        v-model="initialForm.skip_directory_sync"
                        type="checkbox"
                    />
                    Не обновлять справочник перед стартом</label
                >
                <div
                    v-if="Object.keys(initialForm.errors).length"
                    class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700"
                >
                    {{ Object.values(initialForm.errors)[0] }}
                </div>
                <button
                    :disabled="initialForm.processing"
                    class="mt-6 rounded-xl bg-teal px-5 py-3 font-semibold text-white disabled:opacity-50"
                >
                    Запустить разовый обход
                </button>
            </form>

            <form
                class="rounded-3xl border bg-white p-6"
                @submit.prevent="regularForm.submit(startRegular())"
            >
                <h2 class="text-lg font-semibold">Регулярное обновление</h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium"
                        >Регионы<select
                            v-model="regularForm.region_ids"
                            multiple
                            class="h-40 rounded-xl border px-3 py-2"
                        >
                            <option
                                v-for="region in regions"
                                :key="region.id"
                                :value="region.sudrf_region_id"
                            >
                                {{ region.name }}
                            </option>
                        </select></label
                    ><label class="grid gap-2 text-sm font-medium"
                        >Отдельные суды<select
                            v-model="regularForm.court_ids"
                            multiple
                            class="h-40 rounded-xl border px-3 py-2"
                        >
                            <option
                                v-for="court in courts"
                                :key="court.id"
                                :value="court.id"
                            >
                                {{ court.name }}
                            </option>
                        </select></label
                    >
                </div>
                <label class="mt-4 flex items-center gap-3 text-sm"
                    ><input
                        v-model="regularForm.skip_directory_sync"
                        type="checkbox"
                    />
                    Не обновлять справочник перед стартом</label
                >
                <button
                    :disabled="regularForm.processing"
                    class="mt-6 rounded-xl bg-ink px-5 py-3 font-semibold text-white disabled:opacity-50"
                >
                    Запустить регулярный обход
                </button>
            </form>
        </section>

        <section class="mt-6 overflow-hidden rounded-3xl border bg-white">
            <div class="border-b px-6 py-5">
                <h2 class="font-semibold">Кампании</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Кампания</th>
                            <th class="px-5 py-3">Статус</th>
                            <th class="px-5 py-3">Работа</th>
                            <th class="px-5 py-3">Запросы</th>
                            <th class="px-5 py-3">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="campaign in campaigns"
                            :key="campaign.id"
                            class="border-t align-top"
                        >
                            <td class="px-5 py-4">
                                <div class="font-semibold">
                                    #{{ campaign.id }} ·
                                    {{ campaignModeLabel(campaign.mode) }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ campaign.window_from || '—' }} —
                                    {{ campaign.window_to || '—' }}
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge :status="campaign.status" />
                            </td>
                            <td class="px-5 py-4 text-xs">
                                <div>
                                    готово {{ campaign.completed_work_count }}
                                </div>
                                <div>
                                    ожидает {{ campaign.pending_work_count }}
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                {{
                                    campaign.requests_used.toLocaleString(
                                        'ru-RU',
                                    )
                                }}<span v-if="campaign.request_budget">
                                    / план
                                    {{
                                        campaign.request_budget.toLocaleString(
                                            'ru-RU',
                                        )
                                    }}</span
                                >
                            </td>
                            <td class="px-5 py-4">
                                <div
                                    v-if="
                                        ![
                                            'completed',
                                            'cancelled',
                                            'failed',
                                        ].includes(campaign.status)
                                    "
                                    class="flex flex-wrap gap-2"
                                >
                                    <button
                                        v-if="campaign.status === 'running'"
                                        class="rounded-lg border px-3 py-1.5 hover:border-teal"
                                        @click="act(pause(campaign.id))"
                                    >
                                        Остановить</button
                                    ><button
                                        v-else
                                        class="rounded-lg bg-teal px-3 py-1.5 text-white"
                                        @click="act(resume(campaign.id))"
                                    >
                                        Продолжить</button
                                    ><button
                                        class="rounded-lg border px-3 py-1.5"
                                        @click="act(finish(campaign.id))"
                                    >
                                        Завершить</button
                                    ><button
                                        class="rounded-lg border border-red-200 px-3 py-1.5 text-red-700"
                                        @click="
                                            router.visit(cancel(campaign.id), {
                                                preserveScroll: true,
                                            })
                                        "
                                    >
                                        Отменить
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-3xl border bg-white">
            <div class="border-b px-6 py-5">
                <h2 class="font-semibold">Текущая очередь работ</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Суд</th>
                            <th class="px-5 py-3">Тип</th>
                            <th class="px-5 py-3">Дата</th>
                            <th class="px-5 py-3">Статус</th>
                            <th class="px-5 py-3">Ошибка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in workItems"
                            :key="item.id"
                            class="border-t"
                        >
                            <td class="px-5 py-3 font-medium">
                                {{ item.court?.name }}
                            </td>
                            <td class="px-5 py-3">
                                {{ workTypeLabel(item.work_type) }}
                            </td>
                            <td class="px-5 py-3">
                                {{ item.target_date || '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <StatusBadge :status="item.status" />
                            </td>
                            <td class="max-w-md px-5 py-3 text-xs text-red-700">
                                {{ item.last_error || '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
