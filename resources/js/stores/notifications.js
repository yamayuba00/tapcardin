import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/workspace'
import { useAuthStore } from './auth'
import { useToastStore } from './toast'
import { config } from '@/config'
import { connectRealtime } from '@/lib/realtime'
import { TYPES, message } from '@/lib/notifications'

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
  state: () => ({ unread: 0, started: false, listeners: [], mode: 'off', items: [], loaded: false, loading: false, hasMore: true }),
  actions: {
    async start() {
      if (this.started) return
      this.started = true
      // satu panggilan: lencana belum dibaca + isi lonceng sekaligus
      await this.load().catch(() => {})

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
      if (this.loaded && id) this.items.unshift({ id, type, data, read: false, at: new Date().toISOString() })
      const msg = message(type, data)
      if (msg) useToastStore().push(msg, type === 'lost_card_tapped' ? 'danger' : 'info')
      this.listeners.forEach((fn) => fn(type, data))
    },
    /** @returns {() => void} pelepas listener */
    subscribe(fn) {
      this.listeners.push(fn)
      return () => (this.listeners = this.listeners.filter((l) => l !== fn))
    },
    /** Daftar untuk lonceng & halaman Notifikasi; `more` = halaman berikutnya (kursor id). */
    async load(more = false) {
      if (!more && this.loaded) return
      if (this.loading || (more && !this.hasMore)) return
      this.loading = true
      try {
        const before = more ? this.items[this.items.length - 1]?.id : undefined
        const res = await notificationsApi.page(before)
        this.items = more ? [...this.items, ...res.data] : res.data
        this.hasMore = res.meta.has_more
        this.unread = res.meta.unread
        this.loaded = true
      } finally {
        this.loading = false
      }
    },
    async markRead(id) {
      const n = this.items.find((x) => x.id === id)
      if (!n || n.read) return
      n.read = true
      this.unread = Math.max(0, this.unread - 1)
      await notificationsApi.markRead([id]).catch(() => {})
    },
    async markAllRead() {
      this.items.forEach((n) => (n.read = true))
      this.unread = 0
      await notificationsApi.markRead()
    },
  },
})
