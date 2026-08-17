<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import AuthLayout from '@/layouts/AuthLayout.vue';
import { update } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    passwordRules?: string;
}>();
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head title="Новый пароль" />
    <AuthLayout>
        <template #title>Задайте новый пароль</template>
        <template #description>{{
            passwordRules || 'Используйте длинный уникальный пароль.'
        }}</template>
        <form class="grid gap-5" @submit.prevent="form.submit(update())">
            <label class="grid gap-2 text-sm font-medium"
                >Email<input
                    v-model="form.email"
                    type="email"
                    required
                    class="rounded-xl border px-4 py-3"
            /></label>
            <label class="grid gap-2 text-sm font-medium"
                >Новый пароль<input
                    v-model="form.password"
                    type="password"
                    required
                    class="rounded-xl border px-4 py-3"
                /><span
                    v-if="form.errors.password"
                    class="text-xs text-red-600"
                    >{{ form.errors.password }}</span
                ></label
            >
            <label class="grid gap-2 text-sm font-medium"
                >Повторите пароль<input
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    class="rounded-xl border px-4 py-3"
            /></label>
            <button
                :disabled="form.processing"
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white"
            >
                Сохранить пароль
            </button>
        </form>
    </AuthLayout>
</template>
