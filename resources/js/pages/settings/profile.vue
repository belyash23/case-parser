<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { SharedProps } from '@/types';
const page = usePage<SharedProps>();
const form = useForm({
    name: page.props.auth.user?.name ?? '',
    email: page.props.auth.user?.email ?? '',
});
</script>
<template>
    <Head title="Профиль" /><AdminLayout
        ><header class="mb-8">
            <h1 class="text-3xl font-bold">Профиль администратора</h1>
        </header>
        <form
            class="grid max-w-2xl gap-5 rounded-3xl border bg-white p-6"
            @submit.prevent="form.submit(ProfileController.update())"
        >
            <label class="grid gap-2 text-sm font-medium"
                >Имя<input
                    v-model="form.name"
                    class="rounded-xl border px-4 py-3"
                /><span class="text-xs text-red-600">{{
                    form.errors.name
                }}</span></label
            ><label class="grid gap-2 text-sm font-medium"
                >Email<input
                    v-model="form.email"
                    type="email"
                    class="rounded-xl border px-4 py-3"
                /><span class="text-xs text-red-600">{{
                    form.errors.email
                }}</span></label
            ><button
                class="w-fit rounded-xl bg-teal px-5 py-3 font-semibold text-white"
            >
                Сохранить
            </button>
        </form></AdminLayout
    >
</template>
