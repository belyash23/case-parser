<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

import StatusBadge from '@/components/StatusBadge.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';

interface Campaign {
    id: number;
    mode: string;
    status: string;
    requests_used: number;
    request_budget?: number;
    pending_work_count: number;
    completed_work_count: number;
    last_heartbeat_at?: string;
}
interface Availability {
    id: number;
    outcome: string;
    http_status?: number;
    checked_at: string;
    duration_ms?: number;
    court?: { name: string };
}
interface Activity {
    id: number;
    action: string;
    created_at: string;
    user?: { name: string };
}
interface Source {
    circuit_status: string;
    circuit_reason?: string;
    cooldown_until?: string;
    last_success_at?: string;
}
interface Run {
    id: number;
    status: string;
    run_type: string;
    total_requests: number;
    successful_requests: number;
    failed_requests: number;
    started_at: string;
    finished_at?: string;
}

const props = defineProps<{
    summary: {
        courts: number;
        cases: number;
        training_cases: number;
        case_instances: number;
        open_incidents: number;
    };
    source?: Source;
    activeCampaign?: Campaign;
    latestRun?: Run;
    availability: Availability[];
    activities: Activity[];
}>();

let refreshTimer: number | undefined;
onMounted(() => {
    refreshTimer = window.setInterval(
        () =>
            router.reload({
                only: [
                    'summary',
                    'source',
                    'activeCampaign',
                    'latestRun',
                    'availability',
                ],
            }),
        15000,
    );
});
onUnmounted(() => window.clearInterval(refreshTimer));
const formatDate = (value?: string) =>
    value ? new Date(value).toLocaleString('ru-RU') : '—';
const campaignModeLabels: Record<string, string> = {
    initial: 'Разовый обход',
    regular: 'Регулярный обход',
};
const circuitReasonLabels: Record<string, string> = {
    http_403: 'Ответ HTTP 403',
    http_429: 'Ответ HTTP 429',
    consecutive_timeouts: 'Серия таймаутов',
    half_open_timeout: 'Таймаут пробного запроса',
    half_open_network_failure: 'Сетевая ошибка пробного запроса',
};
const activityLabels: Record<string, string> = {
    'settings.updated': 'Настройки изменены',
    'report.queued': 'Отчёт поставлен в очередь',
    'report.downloaded': 'Отчёт скачан',
    'parser.initial_queued': 'Разовый обход поставлен в очередь',
    'parser.regular_queued': 'Регулярный обход поставлен в очередь',
    'parser.paused': 'Обход приостановлен',
    'parser.resumed': 'Обход продолжен',
    'parser.finished_early': 'Обход завершён вручную',
    'parser.cancelled': 'Обход отменён',
};
const campaignModeLabel = (mode?: string): string =>
    mode ? (campaignModeLabels[mode] ?? 'Неизвестный режим') : '—';
const circuitReasonLabel = (reason?: string): string =>
    reason ? (circuitReasonLabels[reason] ?? 'Неизвестная причина') : 'нет';
const activityLabel = (action: string): string =>
    activityLabels[action] ?? 'Неизвестное действие';
</script>

<template>
    <Head title="Обзор" />
    <AdminLayout>
        <header class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight">Обзор парсера</h1>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <article
                v-for="item in [
                    ['Суды', props.summary.courts],
                    ['Дела', props.summary.cases],
                    ['Рассмотрения', props.summary.case_instances],
                    ['Для обучения', props.summary.training_cases],
                    ['Инциденты', props.summary.open_incidents],
                ]"
                :key="String(item[0])"
                class="rounded-2xl border bg-white p-5 shadow-sm"
            >
                <div class="text-sm text-slate-500">{{ item[0] }}</div>
                <div class="mt-2 text-3xl font-bold">
                    {{ Number(item[1]).toLocaleString('ru-RU') }}
                </div>
            </article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-2">
            <article class="rounded-3xl bg-ink p-6 text-white">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold">Источник SUDRF</h2>
                    <StatusBadge :status="source?.circuit_status" />
                </div>
                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-400">Причина приостановки</dt>
                        <dd class="mt-1">
                            {{ circuitReasonLabel(source?.circuit_reason) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Пауза до</dt>
                        <dd class="mt-1">
                            {{ formatDate(source?.cooldown_until) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Последний успешный ответ</dt>
                        <dd class="mt-1">
                            {{ formatDate(source?.last_success_at) }}
                        </dd>
                    </div>
                </dl>
            </article>
            <article class="rounded-3xl border bg-white p-6">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold">Активная кампания</h2>
                    <StatusBadge :status="activeCampaign?.status" />
                </div>
                <template v-if="activeCampaign">
                    <div class="mt-5 text-2xl font-bold">
                        #{{ activeCampaign.id }} ·
                        {{ campaignModeLabel(activeCampaign.mode) }}
                    </div>
                    <div
                        class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"
                    >
                        <div
                            class="h-full bg-teal"
                            :style="{
                                width: `${Math.min(100, (activeCampaign.completed_work_count * 100) / Math.max(1, activeCampaign.completed_work_count + activeCampaign.pending_work_count))}%`,
                            }"
                        />
                    </div>
                    <div
                        class="mt-3 flex justify-between text-xs text-slate-500"
                    >
                        <span
                            >Завершено:
                            {{ activeCampaign.completed_work_count }}</span
                        ><span
                            >Ожидает:
                            {{ activeCampaign.pending_work_count }}</span
                        >
                    </div>
                </template>
                <p v-else class="mt-5 text-sm text-slate-500">
                    Нет незавершённой кампании.
                </p>
            </article>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.35fr_1fr]">
            <article class="overflow-hidden rounded-3xl border bg-white">
                <div class="border-b px-6 py-5">
                    <h2 class="font-semibold">
                        Последние проверки доступности
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Суд</th>
                                <th class="px-5 py-3">Результат</th>
                                <th class="px-5 py-3">HTTP</th>
                                <th class="px-5 py-3">Время</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="check in availability"
                                :key="check.id"
                                class="border-t"
                            >
                                <td class="px-5 py-3 font-medium">
                                    {{ check.court?.name || '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    <StatusBadge :status="check.outcome" />
                                </td>
                                <td class="px-5 py-3">
                                    {{ check.http_status || '—' }}
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ formatDate(check.checked_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="rounded-3xl border bg-white p-6">
                <h2 class="font-semibold">Последние действия</h2>
                <div class="mt-5 grid gap-4">
                    <div
                        v-for="activity in activities"
                        :key="activity.id"
                        class="border-l-2 border-mint pl-4"
                    >
                        <div class="text-sm font-medium">
                            {{ activityLabel(activity.action) }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ activity.user?.name }} ·
                            {{ formatDate(activity.created_at) }}
                        </div>
                    </div>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
