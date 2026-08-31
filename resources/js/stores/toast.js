import { defineStore } from 'pinia';

let seq = 0;

/**
 * App-wide notification queue — one of the few things that legitimately
 * belongs in a global store (see state-management principles: current
 * user, theme, feature flags, notification queue, nothing else).
 */
export const useToastStore = defineStore('toast', {
  state: () => ({ items: [] }),
  actions: {
    push(message, { variant = 'info', timeout = 5000 } = {}) {
      const id = ++seq;
      this.items.push({ id, message, variant });
      if (timeout) {
        setTimeout(() => this.dismiss(id), timeout);
      }
      return id;
    },
    success(message, opts) {
      return this.push(message, { ...opts, variant: 'success' });
    },
    error(message, opts) {
      return this.push(message, { ...opts, variant: 'danger', timeout: opts?.timeout ?? 7000 });
    },
    warning(message, opts) {
      return this.push(message, { ...opts, variant: 'warning' });
    },
    dismiss(id) {
      this.items = this.items.filter((item) => item.id !== id);
    },
  },
});
