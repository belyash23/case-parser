<template>
    <main class="flex min-h-screen items-center justify-center bg-ink p-6">
        <section
            class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl shadow-black/25"
        >
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold tracking-tight">
                    <slot name="title" />
                </h1>
                <p
                    v-if="$slots.description"
                    class="mt-2 text-sm leading-6 text-slate-500"
                >
                    <slot name="description" />
                </p>
            </div>
            <slot />
        </section>
    </main>
</template>
