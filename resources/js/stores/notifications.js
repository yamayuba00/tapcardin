import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/workspace'
import { useToastStore } from './toast'

const TYPES = ['tap', 'lead_new', 'lost_card_tapped', 'saved_back', 'reminder_due', 'import_done', 'invoice_paid', 'invoice_due', 'plan_changed', 'order_status', 'announcement']

const messages = {
  tap: (d) => `Seseorang membuka profil Anda${d.city ? ' · ' + d.city : ''}`,
  lead_new: (d) => `${d.name} terhubung${d.company ? ' · ' + d.company : ''}`,
  lost_card_tapped: (d) => `Kartu hilang Anda baru di-tap${d.city ? ' di ' + d.city : ''}`,
  saved_back: (d) => `${d.name} menyimpan Anda sebagai koneksi. Buka Scan untuk simpan balik.`,
  reminder_due: (d) => `Waktunya follow-up ${d.name}${d.note ? ' · ' + d.note : ''}`,
  invoice_paid: (d) => `Pembayaran ${d.number} berhasil — paket ${d.plan} aktif`,
  invoice_due: (d) => `Tagihan perpanjangan ${d.number} sudah terbit`,
  plan_changed: (d) => `Paket workspace kini ${d.plan}`,
  announcement: (d) => `${d.title} — ${d.body}`,
  import_done: (d) => `Impor ${d.filename} selesai: ${d.created} baru, ${d.merged} digabung, ${d.failed} gagal`,
}

/** Notifikasi realtime via SSE `/api/stream` (TRD §9) + fallback polling 15 detik. */
export const useNotificationStore = defineStore('notifications', {
  state: () => ({ unread: 0, started: false, listeners: [] }),
  actions: {
    async start() {
      if (this.started) return
      this.started = true
      try {
        this.unread = (await notificationsApi.list()).meta.unread
      } catch { /* abaikan */ }

      if ('EventSource' in window) {
        const es = new EventSource(notificationsApi.streamUrl, { withCredentials: true })
        TYPES.forEach((t) => es.addEventListener(t, (e) => this.receive(t, JSON.parse(e.data || '{}'))))
        return
      }
      let since = 0
      const poll = async () => {
        try {
          const rows = (await notificationsApi.list(since)).data.slice().reverse()
          if (since) rows.forEach((n) => this.receive(n.type, n.data))
          since = rows.length ? rows[rows.length - 1].id : since
        } catch { /* coba lagi */ }
        setTimeout(poll, 15000)
      }
      poll()
    },
    receive(type, data) {
      this.unread++
      const msg = messages[type]?.(data)
      if (msg) useToastStore().push(msg, type === 'lost_card_tapped' ? 'danger' : 'info')
      this.listeners.forEach((fn) => fn(type, data))
    },
    /** @returns {() => void} pelepas listener */
    subscribe(fn) {
      this.listeners.push(fn)
      return () => (this.listeners = this.listeners.filter((l) => l !== fn))
    },
    async markRead() {
      await notificationsApi.markRead()
      this.unread = 0
    },
  },
})
