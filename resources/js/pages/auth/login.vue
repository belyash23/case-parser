<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';

defineProps<{ status?: string }>();

const form = useForm({ login: '', password: '', remember: false });
const submit = () => form.submit(store());
</script>

<template>
    <Head title="Авторизация" />
    <AuthLayout>
        <template #title>Авторизация</template>

        <div
            v-if="status"
            class="mb-5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"
        >
            {{ status }}
        </div>

        <form class="grid gap-5" @submit.prevent="submit">
            <label class="grid gap-2 text-sm font-medium">
                Логин
                <input
                    v-model="form.login"
                    type="text"
                    autocomplete="username"
                    required
                    autofocus
                    class="rounded-xl border bg-white px-4 py-3"
                />
                <span v-if="form.errors.login" class="text-xs text-red-600">
                    {{ form.errors.login }}
                </span>
            </label>

            <label class="grid gap-2 text-sm font-medium">
                Пароль
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="rounded-xl border bg-white px-4 py-3"
                />
                <span v-if="form.errors.password" class="text-xs text-red-600">
                    {{ form.errors.password }}
                </span>
            </label>

            <label class="flex items-center gap-3 text-sm text-slate-600">
                <input v-model="form.remember" type="checkbox" class="size-4" />
                Запомнить меня
            </label>

            <button
                :disabled="form.processing"
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Входим…' : 'Войти' }}
            </button>
        </form>
    </AuthLayout>
</template>
