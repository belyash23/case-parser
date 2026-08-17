<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import { index } from '@/actions/App/Http/Controllers/Admin/CaseController';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { Paginator } from '@/types';

interface Instance {
    id: number;
    case_id: number;
    external_case_number: string;
    case_type?: string;
    category_normalized?: string;
    dispute_status_normalized?: string;
    result_normalized?: string;
    started_at?: string;
    completed_at?: string;
    source_url: string;
    court?: { name: string };
    court_case?: { is_training_candidate: boolean; chain_status?: string };
}
interface Coverage {
    id: number;
    name: string;
    case_instances_count: number;
    case_instances_min_started_at?: string;
    case_instances_max_started_at?: string;
}
interface MonthlyCoverage {
    court_id: number;
    month: string;
    total: number;
}
interface Option {
    id: number;
    name: string;
}

const props = defineProps<{
    instances: Paginator<Instance>;
    filters: Record<string, any>;
    courts: Option[];
    regions: Option[];
    coverage: Coverage[];
    monthlyCoverage: MonthlyCoverage[];
}>();
const filters = reactive({
    search: props.filters.search ?? '',
    court_id: props.filters.court_id ?? '',
    region_id: props.filters.region_id ?? '',
    status: props.filters.status ?? '',
    training_only: Boolean(props.filters.training_only),
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const submit = () => {
    const query = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== '' && value !== false,
        ),
    );
    router.visit(index({ query }), {
        preserveState: true,
        preserveScroll: true,
    });
};
const resultLabels: Record<string, string> = {
    joined_to_another_case: 'Присоединено к другому делу',
    transferred_by_jurisdiction: 'Передано по подсудности',
    partially_satisfied: 'Удовлетворено частично',
    satisfied: 'Удовлетворено',
    denied: 'Отказано',
    terminated: 'Производство прекращено',
    left_without_consideration: 'Оставлено без рассмотрения',
    returned: 'Возвращено',
    scheduled: 'Назначено',
    postponed: 'Отложено',
    other: 'Иной результат',
};
const resultLabel = (result?: string): string =>
    result ? (resultLabels[result] ?? 'Неизвестный результат') : '—';
</script>

<template>
    <Head title="Дела и покрытие" />
    <AdminLayout>
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Дела и покрытие</h1>
        </header>
        <form
            class="grid gap-4 rounded-3xl border bg-white p-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-7"
            @submit.prevent="submit"
        >
            <label
                class="grid gap-1 text-xs font-semibold text-slate-500 2xl:col-span-2"
                >Номер дела<input
                    v-model="filters.search"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink"
                    placeholder="2-1234/2025"
            /></label>
            <label class="grid gap-1 text-xs font-semibold text-slate-500"
                >Регион<select
                    v-model="filters.region_id"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink"
                >
                    <option value="">Все</option>
                    <option
                        v-for="region in regions"
                        :key="region.id"
                        :value="region.id"
                    >
                        {{ region.name }}
                    </option>
                </select></label
            >
            <label class="grid gap-1 text-xs font-semibold text-slate-500"
                >Суд<select
                    v-model="filters.court_id"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink"
                >
                    <option value="">Все</option>
                    <option
                        v-for="court in courts"
                        :key="court.id"
                        :value="court.id"
                    >
                        {{ court.name }}
                    </option>
                </select></label
            >
            <label class="grid gap-1 text-xs font-semibold text-slate-500"
                >Статус<select
                    v-model="filters.status"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink"
                >
                    <option value="">Все</option>
                    <option value="active">Активно</option>
                    <option value="resolved">Завершено</option>
                    <option value="unknown">Не определено</option>
                </select></label
            >
            <label class="grid gap-1 text-xs font-semibold text-slate-500"
                >С<input
                    v-model="filters.from"
                    type="date"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink" /></label
            ><label class="grid gap-1 text-xs font-semibold text-slate-500"
                >По<input
                    v-model="filters.to"
                    type="date"
                    class="rounded-xl border px-3 py-2.5 text-sm text-ink"
            /></label>
            <label class="flex items-center gap-2 text-sm md:col-span-2"
                ><input v-model="filters.training_only" type="checkbox" />
                Только кандидаты в датасет</label
            ><button
                class="rounded-xl bg-teal px-5 py-2.5 font-semibold text-white"
            >
                Применить
            </button>
        </form>

        <section class="mt-6 overflow-hidden rounded-3xl border bg-white">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-5 py-3">ID / номер</th>
                            <th class="px-5 py-3">Суд</th>
                            <th class="px-5 py-3">Период</th>
                            <th class="px-5 py-3">Статус</th>
                            <th class="px-5 py-3">Результат</th>
                            <th class="px-5 py-3">Датасет</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in instances.data"
                            :key="item.id"
                            class="border-t"
                        >
                            <td class="px-5 py-4">
                                <div class="font-semibold">
                                    {{ item.external_case_number }}
                                </div>
                                <div class="text-xs text-slate-400">
                                    рассмотрение #{{ item.id }} · дело #{{
                                        item.case_id
                                    }}
                                </div>
                            </td>
                            <td class="px-5 py-4">{{ item.court?.name }}</td>
                            <td class="px-5 py-4 text-xs">
                                {{ item.started_at || '—' }}<br />{{
                                    item.completed_at || '—'
                                }}
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :status="item.dispute_status_normalized"
                                />
                            </td>
                            <td class="max-w-xs px-5 py-4 text-xs">
                                {{ resultLabel(item.result_normalized) }}
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :status="
                                        item.court_case?.is_training_candidate
                                            ? 'ready'
                                            : 'excluded'
                                    "
                                />
                            </td>
                            <td class="px-5 py-4">
                                <a
                                    :href="item.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-medium text-teal hover:underline"
                                    >Карточка ↗</a
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t p-5">
                <Pagination :links="instances.links" />
            </div>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
            <article class="overflow-hidden rounded-3xl border bg-white">
                <div class="border-b px-6 py-5">
                    <h2 class="font-semibold">Покрытие по судам</h2>
                </div>
                <div class="max-h-[520px] overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="sticky top-0 bg-slate-50 text-xs text-slate-500"
                        >
                            <tr>
                                <th class="px-5 py-3">Суд</th>
                                <th class="px-5 py-3">Рассмотрений</th>
                                <th class="px-5 py-3">Первая дата</th>
                                <th class="px-5 py-3">Последняя дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="court in coverage"
                                :key="court.id"
                                class="border-t"
                            >
                                <td class="px-5 py-3 font-medium">
                                    {{ court.name }}
                                </td>
                                <td class="px-5 py-3">
                                    {{ court.case_instances_count }}
                                </td>
                                <td class="px-5 py-3">
                                    {{
                                        court.case_instances_min_started_at ||
                                        '—'
                                    }}
                                </td>
                                <td class="px-5 py-3">
                                    {{
                                        court.case_instances_max_started_at ||
                                        '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="rounded-3xl border bg-white p-6">
                <h2 class="font-semibold">Последние месяцы</h2>
                <div class="mt-5 grid max-h-[450px] gap-2 overflow-auto">
                    <div
                        v-for="row in monthlyCoverage"
                        :key="`${row.court_id}-${row.month}`"
                        class="flex justify-between rounded-xl bg-slate-50 px-4 py-2 text-sm"
                    >
                        <span>Суд #{{ row.court_id }} · {{ row.month }}</span
                        ><strong>{{ row.total }}</strong>
                    </div>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
