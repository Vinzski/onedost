import { reactive } from 'vue';
const state = reactive({ toasts: [], _next: 0 });
export const toast = {
    state,
    add(message, type = 'success', duration = 5000) {
        const id = ++state._next;
        state.toasts.push({ id, message, type });
        if (duration > 0) setTimeout(() => this.remove(id), duration);
        return id;
    },
    remove(id) {
        const i = state.toasts.findIndex(t => t.id === id);
        if (i !== -1) state.toasts.splice(i, 1);
    },
};
