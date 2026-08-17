<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status?: string | null }>();
const labels: Record<string, string> = {
    active: 'Активно',
    cancelled: 'Отменено',
    closed: 'Работает',
    completed: 'Завершено',
    confirmed: 'Подтверждено',
    connect_timeout: 'Таймаут подключения',
    dns_error: 'Ошибка DNS',
    excluded: 'Не включено',
    expired: 'Истёк срок хранения',
    failed: 'Ошибка',
    forbidden: 'Доступ запрещён',
    half_open: 'Пробная проверка',
    http_4xx: 'Ошибка HTTP 4xx',
    http_5xx: 'Ошибка HTTP 5xx',
    merged: 'Объединено',
    network_error: 'Ошибка сети',
    open: 'Приостановлен',
    paused: 'Приостановлено',
    pending: 'Ожидает',
    queued: 'В очереди',
    rate_limited: 'Слишком много запросов',
    read_timeout: 'Таймаут ответа',
    ready: 'Готово',
    resolved: 'Завершено',
    running: 'Выполняется',
    success: 'Успешно',
    suspected: 'Требует проверки',
    tls_error: 'Ошибка TLS',
    transferred: 'Передано',
    unexpected_http_status: 'Неожиданный HTTP-статус',
    unknown: 'Не определено',
};
const label = computed(() =>
    props.status ? (labels[props.status] ?? 'Неизвестный статус') : '—',
);
const colors = computed(() => {
    if (
        ['completed', 'ready', 'success', 'closed', 'resolved'].includes(
            props.status ?? '',
        )
    ) {
        return 'bg-emerald-100 text-emerald-800';
    }
    if (
        [
            'failed',
            'cancelled',
            'confirmed',
            'open',
            'forbidden',
            'rate_limited',
            'http_4xx',
            'http_5xx',
            'unexpected_http_status',
            'dns_error',
            'connect_timeout',
            'read_timeout',
            'tls_error',
            'network_error',
        ].includes(props.status ?? '')
    ) {
        return 'bg-red-100 text-red-800';
    }
    if (['running', 'active', 'half_open'].includes(props.status ?? '')) {
        return 'bg-blue-100 text-blue-800';
    }
    if (
        ['paused', 'suspected', 'queued', 'pending'].includes(
            props.status ?? '',
        )
    ) {
        return 'bg-amber-100 text-amber-900';
    }
    return 'bg-slate-100 text-slate-700';
});
</script>

<template>
    <span
        :class="[
            'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
            colors,
        ]"
    >
        {{ label }}
    </span>
</template>
