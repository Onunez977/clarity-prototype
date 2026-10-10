import { ref } from 'vue';

const toasts = ref([]);
let nextId = 1;

export function useToast() {
    function show(message, { type = 'info', duration = 4000, action = null, notificationId = null } = {}) {
        const id = nextId++;
        toasts.value.push({ id, message, type, action, notificationId });
        if (duration > 0) {
            setTimeout(() => dismiss(id), duration);
        }
        return id;
    }

    function dismiss(id) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return { toasts, show, dismiss };
}
