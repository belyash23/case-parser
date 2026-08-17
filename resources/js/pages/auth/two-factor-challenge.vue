<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/two-factor/login';
const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });
const submit = () => form.submit(store());
</script>
<template>
    <Head title="Двухфакторная проверка" /><AuthLayout
        ><template #title>Двухфакторная проверка</template
        ><template #description>{{
            recovery
                ? 'Введите один из резервных кодов.'
                : 'Введите код из приложения-аутентификатора.'
        }}</template>
        <form class="grid gap-5" @submit.prevent="submit">
            <input
                v-if="!recovery"
                v-model="form.code"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                class="rounded-xl border px-4 py-3 text-center text-lg tracking-[0.3em]"
            /><input
                v-else
                v-model="form.recovery_code"
                autocomplete="one-time-code"
                autofocus
                class="rounded-xl border px-4 py-3"
            />
            <div class="text-sm text-red-600">
                {{ form.errors.code || form.errors.recovery_code }}
            </div>
            <button
                class="rounded-xl bg-teal px-4 py-3 font-semibold text-white"
            >
                Подтвердить</button
            ><button
                type="button"
                class="text-sm text-teal"
                @click="recovery = !recovery"
            >
                {{
                    recovery
                        ? 'Использовать обычный код'
                        : 'Использовать резервный код'
                }}
            </button>
        </form></AuthLayout
    >
</template>
