import { defineStore } from 'pinia'

let seq = 0

export const useToastStore = defineStore('toast', {
  state: () => ({ items: [] }),
  actions: {
    /** @param {string} message @param {'info'|'ok'|'danger'} [kind] @param {string} [key] toast berkunci sama diganti, bukan ditumpuk */
    push(message, kind = 'info', key = null) {
      const same = key && this.items.find((t) => t.key === key)
      if (same) {
        same.message = message
        clearTimeout(same.timer)
        same.timer = setTimeout(() => this.dismiss(same.id), 4000)
        return
      }
      const id = ++seq
      const t = { id, key, message, kind, timer: null }
      this.items.push(t)
      t.timer = setTimeout(() => this.dismiss(id), 4000)
    },
    ok(message) { this.push(message, 'ok') },
    error(message) { this.push(message, 'danger') },
    dismiss(id) {
      this.items = this.items.filter((t) => t.id !== id)
    },
  },
})
