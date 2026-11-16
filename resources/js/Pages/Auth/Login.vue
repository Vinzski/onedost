<script>
import { useForm } from '@inertiajs/vue3';
import { themeMixin } from '@/Shared/mixins/themeMixin.js';

export default {
    layout: null,

    mixins: [themeMixin],

    data() {
        return {
            form: useForm({ email: '', password: '', remember: true }),
            showPassword: false,
        };
    },

    methods: {
        submit() {
            this.form.post(route('login.store'), {
                onFinish: () => this.form.reset('password'),
            });
        },
    },
};
</script>

<template>
    <Head title="Log in" />

    <main class="login-page">
        <div class="card login-card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="ri-apps-2-line fs-4 brand-icon"></i>
                    <h1 class="h5 mb-0">OneDOST</h1>
                </div>
                <p class="small text-body-secondary mb-4">Log in to see your records.</p>

                <form novalidate @submit.prevent="submit">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" v-model="form.email" type="email" class="form-control" autocomplete="username" autofocus required
                               :class="{ 'is-invalid': form.errors.email }">
                        <div class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group has-validation">
                            <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" class="form-control"
                                   autocomplete="current-password" required :class="{ 'is-invalid': form.errors.password }">
                            <button type="button" class="btn btn-outline-secondary" :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    @click="showPassword = !showPassword">
                                <i :class="showPassword ? 'ri-eye-off-line' : 'ri-eye-line'"></i>
                            </button>
                            <div class="invalid-feedback">{{ form.errors.password }}</div>
                        </div>
                    </div>
                    <div class="form-check mb-4">
                        <input id="remember" v-model="form.remember" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="remember">Keep me logged in on this device</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Log in
                    </button>
                </form>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-link text-body-secondary mt-3" @click="cycleTheme">
            <i :class="themeIcon" class="me-1"></i>{{ themeLabel }}
        </button>
        <ToastContainer />
    </main>
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: var(--surface-page);
}
.login-card { width: 100%; max-width: 24rem; box-shadow: var(--shadow-pop); }
.brand-icon { color: var(--brand); }
</style>
