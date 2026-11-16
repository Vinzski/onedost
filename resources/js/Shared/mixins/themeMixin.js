import { router } from '@inertiajs/vue3';
export const themeMixin = {
    data() { return { currentTheme: localStorage.getItem('theme') || 'system' }; },
    computed: {
        themeIcon()  { return { light: 'ri-sun-line', dark: 'ri-moon-line', system: 'ri-computer-line' }[this.currentTheme]; },
        themeLabel() { return { light: 'Light', dark: 'Dark', system: 'System' }[this.currentTheme]; },
    },
    methods: {
        cycleTheme() { this.setTheme({ light: 'dark', dark: 'system', system: 'light' }[this.currentTheme]); },
        setTheme(pref) {
            this.currentTheme = pref;
            this.applyTheme(pref);
            localStorage.setItem('theme', pref);
            // Persists per user once a `user.theme` route exists; until then localStorage is enough.
            if (typeof route !== 'undefined' && route().has('user.theme')) {
                router.patch(route('user.theme'), { preference: pref },
                    { preserveState: true, preserveScroll: true, only: [] });
            }
        },
        applyTheme(pref) {
            const resolved = pref === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : pref;
            document.documentElement.setAttribute('data-bs-theme', resolved);
        },
    },
};
