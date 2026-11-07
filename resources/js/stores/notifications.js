import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/workspace'
import { useAuthStore } from './auth'
import { useToastStore } from './toast'
import { config } from '@/config'
import { connectRealtime } from '@/lib/realtime'

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
  order_status: (d) => `Pesanan ${d.number}: ${d.label}${d.tracking ? ' · resi ' + d.tracking : ''}`,
  announcement: (d) => `${d.title} — ${d.body}`,
  import_done: (d) => `Impor ${d.filename} selesai: ${d.created} baru, ${d.merged} digabung, ${d.failed} gagal`,
}

// transport aktif (di luar state Pinia agar tidak dibuat reaktif)
let es = null
let pollTimer = null
let fallbackTimer = null
const seen = new Set() // id notifikasi yang sudah ditampilkan (Reverb & SSE bisa sempat aktif bersamaan)

/**
 * Notifikasi realtime: Reverb (WebSocket) bila tersedia; cadangan otomatis ke SSE `/api/stream` (TRD §9) bila WebSocket
 * tidak tersambung, lalu polling 15 detik bila browser tanpa EventSource. `mode` = connecting | realtime | sse | poll.
 */
export const useNotificationStore = defineStore('notifications', {
  state: () => ({ unread: 0, started: false, listeners: [], mode: 'off' }),
  actions: {
    async start() {
      if (this.started) return
      this.started = true
      try {
        this.unread = (await notificationsApi.list()).meta.unread
      } catch { /* abaikan */ }

      const auth = useAuthStore()
      if (!config.realtime || !auth.me?.id) return this.startSse()
      this.mode = 'connecting'
      fallbackTimer = setTimeout(() => this.mode !== 'realtime' && this.startSse(), 10000)
      const onNotification = (p) => {
        if (p.tenant && p.tenant !== auth.workspace?.id) return // notifikasi workspace lain
        this.receive(p.type, p.data, p.id)
      }
      const onState = (state) => {
        if (state === 'connected') {
          clearTimeout(fallbackTimer)
          this.stopSse()
          this.mode = 'realtime'
        } else if (state === 'unavailable' || state === 'failed') {
          this.startSse()
        }
      }
      connectRealtime(config.realtime, auth.me.id, onNotification, onState).catch(() => this.startSse())
    },
    /** Cadangan: SSE, atau polling bila EventSource tidak ada. Tidak berjalan ganda. */
    startSse() {
      if (es || pollTimer) return
      if ('EventSource' in window) {
        es = new EventSource(notificationsApi.streamUrl, { withCredentials: true })
        TYPES.forEach((t) => es.addEventListener(t, (e) => this.receive(t, JSON.parse(e.data || '{}'), Number(e.lastEventId) || null)))
        this.mode = 'sse'
        return
      }
      let since = 0
      const poll = async () => {
        try {
          const rows = (await notificationsApi.list(since)).data.slice().reverse()
          if (since) rows.forEach((n) => this.receive(n.type, n.data, n.id))
          since = rows.length ? rows[rows.length - 1].id : since
        } catch { /* coba lagi */ }
        pollTimer = setTimeout(poll, 15000)
      }
      this.mode = 'poll'
      poll()
    },
    stopSse() {
      es?.close()
      es = null
      clearTimeout(pollTimer)
      pollTimer = null
    },
    receive(type, data, id = null) {
      if (id) {
        if (seen.has(id)) return
        seen.add(id)
        if (seen.size > 500) seen.delete(seen.values().next().value)
      }
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
