import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} - Case Parser` : 'Case Parser'),
    pages: './pages',
});
