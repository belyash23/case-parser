<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import { update } from '@/actions/App/Http/Controllers/Admin/ParserSettingController';
import AdminLayout from '@/layouts/AdminLayout.vue';

interface Settings {
    [key: string]: number | boolean | string;
}
const props = defineProps<{
    settings: Settings;
    limits: {
        minimum_request_interval_ms: number;
        recommended_request_interval_ms: number;
    };
}>();
const keys = [
    'request_interval_ms',
    'connect_timeout_seconds',
    'directory_timeout_seconds',
    'forbidden_cooldown_seconds',
    'rate_limit_cooldown_seconds',
    'timeout_circuit_threshold',
    'timeout_cooldown_seconds',
    'monitoring_enabled',
    'monitor_interval_minutes',
    'monitor_reuse_window_minutes',
    'monitor_timeout_seconds',
    'monitor_connect_timeout_seconds',
    'regular_scheduling_enabled',
    'regular_slice_seconds',
    'capacity_utilization_percent',
    'base_budget_percent',
    'maximum_court_share_percent',
    'initial_maximum_case_attempts',
    'regular_maximum_case_attempts',
    'regular_failure_retry_minutes',
    'regular_recheck_interval_days',
    'regular_recheck_starvation_days',
];
const form = useForm<Record<string, number | boolean>>(
    Object.fromEntries(keys.map((key) => [key, props.settings[key]])) as Record<
        string,
        number | boolean
    >,
);
const unsafeInterval = computed(
    () =>
        Number(form.request_interval_ms) <
        props.limits.recommended_request_interval_ms,
);
const groups = [
    {
        title: 'HTTP и защита источника',
        fields: [
            ['request_interval_ms', 'Интервал между запросами, мс'],
            ['connect_timeout_seconds', 'Таймаут подключения, сек'],
            ['directory_timeout_seconds', 'Таймаут справочника, сек'],
            ['forbidden_cooldown_seconds', 'Пауза после 403, сек'],
            ['rate_limit_cooldown_seconds', 'Пауза после 429, сек'],
            ['timeout_circuit_threshold', 'Таймаутов до защитной паузы'],
            ['timeout_cooldown_seconds', 'Пауза после таймаутов, сек'],
        ],
    },
    {
        title: 'Мониторинг',
        fields: [
            ['monitor_interval_minutes', 'Интервал мониторинга, мин'],
            [
                'monitor_reuse_window_minutes',
                'Окно переиспользования запросов, мин',
            ],
            ['monitor_timeout_seconds', 'Таймаут проверки, сек'],
            [
                'monitor_connect_timeout_seconds',
                'Таймаут подключения проверки, сек',
            ],
        ],
    },
    {
        title: 'Регулярный обход и распределение',
        fields: [
            ['regular_slice_seconds', 'Длительность одного запуска, сек'],
            [
                'capacity_utilization_percent',
                'Плановая доля месячной ёмкости, %',
            ],
            ['base_budget_percent', 'Базовая доля плановой ёмкости, %'],
            [
                'maximum_court_share_percent',
                'Максимальная доля суда в плане, %',
            ],
            [
                'initial_maximum_case_attempts',
                'Попыток карточки в разовом обходе',
            ],
            [
                'regular_maximum_case_attempts',
                'Попыток карточки в регулярном обходе',
            ],
            ['regular_failure_retry_minutes', 'Повтор ошибки через, мин'],
            [
                'regular_recheck_interval_days',
                'Интервал повторной проверки, дней',
            ],
            [
                'regular_recheck_starvation_days',
                'Порог повышения приоритета, дней',
            ],
        ],
    },
];
</script>

<template>
    <Head title="Настройки" />
    <AdminLayout>
        <header class="mb-8">
            <h1 class="text-3xl font-bold">Настройки парсера</h1>
        </header>
        <form class="grid gap-6" @submit.prevent="form.submit(update())">
            <div
                v-if="unsafeInterval"
                class="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900"
            >
                <strong>Внимание:</strong> выбран интервал меньше 10 секунд.
                Система разрешит его, но это повышает нагрузку на SUDRF.
            </div>
            <section
                class="grid gap-4 rounded-3xl border bg-white p-6 sm:grid-cols-2"
            >
                <label
                    class="flex items-center justify-between gap-5 rounded-2xl bg-slate-50 p-4"
                    ><span
                        ><strong class="block text-sm"
                            >Мониторинг доступности</strong
                        ><span class="mt-1 block text-xs text-slate-500"
                            >Проверки запускаются только когда нет свежих
                            запросов парсера.</span
                        ></span
                    ><input
                        v-model="form.monitoring_enabled"
                        type="checkbox"
                        class="size-5"
                /></label>
                <label
                    class="flex items-center justify-between gap-5 rounded-2xl bg-slate-50 p-4"
                    ><span
                        ><strong class="block text-sm"
                            >Регулярный планировщик</strong
                        ><span class="mt-1 block text-xs text-slate-500"
                            >Автоматически продолжает месячную кампанию.</span
                        ></span
                    ><input
                        v-model="form.regular_scheduling_enabled"
                        type="checkbox"
                        class="size-5"
                /></label>
            </section>
            <section
                v-for="group in groups"
                :key="group.title"
                class="rounded-3xl border bg-white p-6"
            >
                <h2 class="text-lg font-semibold">{{ group.title }}</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <label
                        v-for="field in group.fields"
                        :key="field[0]"
                        class="grid gap-2 text-sm font-medium"
                        >{{ field[1]
                        }}<input
                            v-model.number="form[field[0]]"
                            type="number"
                            class="rounded-xl border px-3 py-2.5"
                        /><span
                            v-if="form.errors[field[0]]"
                            class="text-xs text-red-600"
                            >{{ form.errors[field[0]] }}</span
                        ></label
                    >
                </div>
            </section>
            <div class="sticky bottom-4 flex justify-end">
                <button
                    :disabled="form.processing"
                    class="rounded-xl bg-teal px-6 py-3 font-semibold text-white shadow-lg disabled:opacity-50"
                >
                    {{ form.processing ? 'Сохраняем…' : 'Сохранить настройки' }}
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
