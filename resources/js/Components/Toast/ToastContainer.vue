<script>
export default {
    computed: { toasts() { return this.$toast.state.toasts; } },
    methods: {
        icon(type) {
            return { success: 'ri-checkbox-circle-line', warning: 'ri-alert-line',
                     error: 'ri-error-warning-line', info: 'ri-information-line' }[type] ?? 'ri-information-line';
        },
        variant(type) {
            return { success: 'success', warning: 'warning', error: 'danger', info: 'info' }[type] ?? 'secondary';
        },
    },
};
</script>
<template>
    <div class="toast-stack position-fixed top-0 end-0 p-3">
        <TransitionGroup name="toast-fade">
            <div v-for="t in toasts" :key="t.id"
                 :class="`toast-item alert alert-${variant(t.type)} alert-dismissible d-flex align-items-center gap-2 shadow-sm mb-2`">
                <i :class="icon(t.type)"></i>
                <span>{{ t.message }}</span>
                <button type="button" class="btn-close" @click="$toast.remove(t.id)"></button>
            </div>
        </TransitionGroup>
    </div>
</template>
