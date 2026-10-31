import { defineStore } from 'pinia'

let seq = 0

export const useToastStore = defineStore('toast', {
  state: () => ({ items: [] }),
  actions: {
    /** @param {string} message @param {'info'|'ok'|'danger'} [kind] */
    push(message, kind = 'info') {
      const id = ++seq
      this.items.push({ id, message, kind })
      setTimeout(() => this.dismiss(id), 4000)
    },
    ok(message) { this.push(message, 'ok') },
    error(message) { this.push(message, 'danger') },
    dismiss(id) {
      this.items = this.items.filter((t) => t.id !== id)
    },
  },
})
