<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';

import {
    download,
    store,
} from '@/actions/App/Http/Controllers/Admin/ReportController';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { Paginator } from '@/types';

interface Report {
    id: number;
    type: string;
    format: string;
    status: string;
    size_bytes?: number;
    error_message?: string;
    created_at: string;
    expires_at: string;
}
defineProps<{ reports: Paginator<Report>; reportTypes: string[] }>();
const today = new Date().toISOString().slice(0, 10);
const monthAgo = new Date(Date.now() - 30 * 86400000)
    .toISOString()
    .slice(0, 10);
const idsText = ref('');
const form = useForm({
    type: 'dataset',
    format: 'csv',
    from: monthAgo,
    to: today,
    court_id: '',
    ids: [] as number[],
    limit: 10,
    include_source_url: false,
});
watch(
    () => form.type,
    (type) => {
        form.format = type === 'case_inspection' ? 'json' : 'csv';
    },
);
const submit = () => {
    form.ids = idsText.value
        .split(',')
        .map((value) => Number(value.trim()))
        .filter((value) => Number.isInteger(value) && value > 0);
    form.submit(store());
};
const fileSize = (bytes?: number) =>
    bytes ? `${(bytes / 1024 / 1024).toFixed(2)} МБ` : '—';
const date = (value: string) => new Date(value).toLocaleString('ru-RU');
const reportTypeLabels: Record<string, string> = {
    dataset: 'Датасет',
    availability: 'Доступность SUDRF',
    case_inspection: 'Диагностика дел',
};
const reportTypeLabel = (type: string): string =>
    reportTypeLabels[type] ?? 'Неизвестный отчёт';
let timer: number | undefined;
onMounted(() => {
    timer = window.setInterval(
        () => router.reload({ only: ['reports'] }),
        10000,
    );
});
onUnmounted(() => window.clearInterval(timer));
</script>

<template>
    <Head title="Отчёты" />
    <AdminLayout>
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Отчёты</h1>
        </header>
        <section class="grid gap-6 xl:grid-cols-[420px_1fr]">
            <form
                class="rounded-3xl border bg-white p-6"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-semibold">Новый отчёт</h2>
                <div class="mt-6 grid gap-4">
                    <label class="grid gap-2 text-sm font-medium"
                        >Тип<select
                            v-model="form.type"
                            class="rounded-xl border px-3 py-2.5"
                        >
                            <option value="dataset">Датасет</option>
                            <option value="availability">
                                Доступность SUDRF
                            </option>
                            <option value="case_inspection">
                                Диагностика дел
                            </option>
                        </select></label
                    >
                    <label class="grid gap-2 text-sm font-medium"
                        >Формат<select
                            v-model="form.format"
                            :disabled="form.type === 'case_inspection'"
                            class="rounded-xl border px-3 py-2.5"
                        >
                            <option value="csv">CSV</option>
                            <option value="jsonl">JSONL</option>
                            <option
                                v-if="form.type === 'case_inspection'"
                                value="json"
                            >
                                JSON
                            </option>
                        </select></label
                    >
                    <div class="grid grid-cols-2 gap-4">
                        <label class="grid gap-2 text-sm font-medium"
                            >С<input
                                v-model="form.from"
                                type="date"
                                class="rounded-xl border px-3 py-2.5" /></label
                        ><label class="grid gap-2 text-sm font-medium"
                            >По<input
                                v-model="form.to"
                                type="date"
                                class="rounded-xl border px-3 py-2.5"
                        /></label>
                    </div>
                    <template v-if="form.type === 'case_inspection'"
                        ><label class="grid gap-2 text-sm font-medium"
                            >ID экземпляров через запятую<input
                                v-model="idsText"
                                class="rounded-xl border px-3 py-2.5"
                                placeholder="1324, 1325"
                        /></label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="grid gap-2 text-sm font-medium"
                                >ID суда<input
                                    v-model="form.court_id"
                                    type="number"
                                    class="rounded-xl border px-3 py-2.5" /></label
                            ><label class="grid gap-2 text-sm font-medium"
                                >Лимит<input
                                    v-model="form.limit"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="rounded-xl border px-3 py-2.5"
                            /></label></div
                    ></template>
                    <label
                        v-if="form.type === 'dataset'"
                        class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.include_source_url"
                            type="checkbox"
                        />
                        Добавить URL карточек</label
                    >
                    <div
                        v-if="Object.keys(form.errors).length"
                        class="rounded-xl bg-red-50 p-3 text-sm text-red-700"
                    >
                        {{ Object.values(form.errors)[0] }}
                    </div>
                    <button
                        :disabled="form.processing"
                        class="rounded-xl bg-teal px-5 py-3 font-semibold text-white disabled:opacity-50"
                    >
                        Создать отчёт
                    </button>
                </div>
            </form>
            <article class="overflow-hidden rounded-3xl border bg-white">
                <div class="border-b px-6 py-5">
                    <h2 class="font-semibold">История выгрузок</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Отчёт</th>
                                <th class="px-5 py-3">Статус</th>
                                <th class="px-5 py-3">Размер</th>
                                <th class="px-5 py-3">Создан</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="report in reports.data"
                                :key="report.id"
                                class="border-t"
                            >
                                <td class="px-5 py-4">
                                    <div class="font-semibold">
                                        #{{ report.id }} ·
                                        {{ reportTypeLabel(report.type) }}
                                    </div>
                                    <div class="mt-1 text-xs text-red-600">
                                        {{ report.error_message }}
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <StatusBadge :status="report.status" />
                                </td>
                                <td class="px-5 py-4">
                                    {{ fileSize(report.size_bytes) }}
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">
                                    {{ date(report.created_at) }}<br />до
                                    {{ date(report.expires_at) }}
                                </td>
                                <td class="px-5 py-4">
                                    <a
                                        v-if="report.status === 'ready'"
                                        :href="download(report.id).url"
                                        class="font-semibold text-teal hover:underline"
                                        >Скачать</a
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t p-5">
                    <Pagination :links="reports.links" />
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
