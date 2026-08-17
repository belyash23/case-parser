<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineProps<{ status?: string }>();
const form = useForm({ email: '' });
</script>

<template>
    <Head title="Восстановление пароля" />
    <AuthLayout>
        <template #title>Восстановление доступа</template>
        <template #description
            >Отправим ссылку для смены пароля на email администратора.</template
        >
        <div
            v-if="status"
            class="mb-5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            {{ status }}
        </div>
        <form class="grid gap-5" @submit.prevent="form.submit(email())">
            <label class="grid gap-2 text-sm font-medium"
                >Email
                <input
                    v-model="form.email"
                    type="email"
                    required
                    autofocus
                    class="rounded-xl border px-4 py-3"
                />
                <span v-if="form.errors.email" class="text-xs text-red-600">{{
                    form.errors.email
                }}</span>
            </label>
            <button
                :disabled="form.processing"
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white"
            >
                Отправить ссылку
            </button>
            <Link
                :href="login()"
                class="text-center text-sm text-teal hover:underline"
                >Вернуться ко входу</Link
            >
        </form>
    </AuthLayout>
</template>
