<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
defineProps<{ status?: string }>();
const form = useForm({});
</script>
<template>
    <Head title="Подтверждение email" /><AuthLayout
        ><template #title>Подтвердите email</template
        ><template #description
            >Перейдите по ссылке из письма или запросите письмо
            повторно.</template
        >
        <div v-if="status" class="mb-5 rounded-xl bg-emerald-50 p-3 text-sm">
            {{ status }}
        </div>
        <div class="grid gap-3">
            <button
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white"
                @click="form.submit(send())"
            >
                Отправить повторно</button
            ><Link
                :href="logout()"
                method="post"
                as="button"
                class="text-sm text-teal"
                >Выйти</Link
            >
        </div></AuthLayout
    >
</template>
