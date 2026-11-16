import './bootstrap';
import '../css/app.css';

import { createBootstrap, Components } from 'bootstrap-vue-next';
import { createInertiaApp, router, Link, Head } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import ToastContainer from '@/Components/Toast/ToastContainer.vue';
import { toast } from '@/Shared/stores/toast.js';

const appName = import.meta.env.VITE_APP_NAME || 'OneDOST';

// Inertia::flash() data arrives once per response — never replayed from
// history or partial reloads — so every flash becomes exactly one toast.
router.on('flash', (event) => {
    const flash = event.detail.flash;
    if (!flash?.message) return;
    const type = flash.info === 'warning' ? 'warning'
               : flash.status === false   ? 'error' : 'success';
    // On failure HandlesTransaction puts the detail in `info`.
    const text = type === 'error' && flash.info ? `${flash.message}: ${flash.info}` : flash.message;
    toast.add(text, type);
});

createInertiaApp({
    title: (title) => (title ? `${appName} — ${title}` : appName),
    resolve: (name) => {
        const page = resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
        page.then((module) => { if (module.default.layout === undefined) module.default.layout = AppLayout; });
        return page;
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin).use(createBootstrap()).use(ZiggyVue)
            .component('Link', Link).component('Head', Head)
            .component('ToastContainer', ToastContainer);

        Object.entries(Components).forEach(([name, component]) => app.component(name, component));
        app.config.globalProperties.$toast = toast;

        return app.mount(el);
    },
    progress: { color: '#0d6efd' },
});
