<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/password/confirm';
const form = useForm({ password: '' });
</script>
<template>
    <Head title="Подтверждение пароля" /><AuthLayout
        ><template #title>Подтвердите пароль</template
        ><template #description>Это защищённое действие.</template>
        <form class="grid gap-5" @submit.prevent="form.submit(store())">
            <label class="grid gap-2 text-sm font-medium"
                >Пароль<input
                    v-model="form.password"
                    type="password"
                    required
                    autofocus
                    class="rounded-xl border px-4 py-3"
                /><span
                    v-if="form.errors.password"
                    class="text-xs text-red-600"
                    >{{ form.errors.password }}</span
                ></label
            ><button
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white"
            >
                Продолжить
            </button>
        </form></AuthLayout
    >
</template>
