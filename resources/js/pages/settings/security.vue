<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import AdminLayout from '@/layouts/AdminLayout.vue';
defineProps<{ passwordRules?: string }>();
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const submit = () =>
    form.submit(SecurityController.update(), { onSuccess: () => form.reset() });
</script>
<template>
    <Head title="Безопасность" /><AdminLayout
        ><header class="mb-8">
            <h1 class="text-3xl font-bold">Безопасность</h1>
        </header>
        <form
            class="grid max-w-2xl gap-5 rounded-3xl border bg-white p-6"
            @submit.prevent="submit"
        >
            <label class="grid gap-2 text-sm font-medium"
                >Текущий пароль<input
                    v-model="form.current_password"
                    type="password"
                    class="rounded-xl border px-4 py-3"
                /><span class="text-xs text-red-600">{{
                    form.errors.current_password
                }}</span></label
            ><label class="grid gap-2 text-sm font-medium"
                >Новый пароль<input
                    v-model="form.password"
                    type="password"
                    class="rounded-xl border px-4 py-3"
                /><span class="text-xs text-red-600">{{
                    form.errors.password
                }}</span></label
            ><label class="grid gap-2 text-sm font-medium"
                >Повторите пароль<input
                    v-model="form.password_confirmation"
                    type="password"
                    class="rounded-xl border px-4 py-3" /></label
            ><button
                class="w-fit rounded-xl bg-teal px-5 py-3 font-semibold text-white"
            >
                Обновить пароль
            </button>
        </form></AdminLayout
    >
</template>
