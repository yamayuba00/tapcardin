import { defineStore } from 'pinia'
import { config } from '@/config'
import { connectUptime } from '@/lib/realtime'
import { useToastStore } from './toast'

const STATUS_TEXT = { up: 'normal kembali', down: 'DOWN', degraded: 'perlu perhatian' }
let disconnect = null

/**
 * Panel: pemantau uptime realtime (channel privat `platform.uptime`), dipasang sekali di AdminLayout.
 * Perubahan status → toast + notifikasi browser (bila diizinkan) + bunyi singkat saat down, di halaman panel mana pun.
 * Halaman Uptime berlangganan `onCheck` untuk memperbarui baris & grafik tanpa memuat ulang.
 */
export const useUptimeStore = defineStore('uptime', {
  state: () => ({ mode: 'off', listeners: [], permission: typeof Notification === 'undefined' ? 'unsupported' : Notification.permission }),
  actions: {
    async start() {
      if (disconnect || !config.realtime) return
      this.mode = 'connecting'
      disconnect = await connectUptime(config.realtime, (p) => this.receive(p), (s) => {
        this.mode = s === 'connected' ? 'realtime' : s === 'connecting' ? 'connecting' : 'offline'
      }).catch(() => {
        this.mode = 'offline'
        return null
      })
    },
    stop() {
      disconnect?.()
      disconnect = null
      this.mode = 'off'
    },
    /** @param {(payload: object) => void} fn @returns {() => void} */
    onCheck(fn) {
      this.listeners.push(fn)
      return () => (this.listeners = this.listeners.filter((l) => l !== fn))
    },
    receive(p) {
      this.listeners.forEach((fn) => fn(p))
      const c = p.change
      if (!c || (c.from === 'pending' && c.to === 'up')) return
      const text = `${p.monitor.name}: ${STATUS_TEXT[c.to] ?? c.to}${c.reason ? ' — ' + c.reason : ''}`
      useToastStore().push(text, c.to === 'up' ? 'ok' : 'danger')
      if (this.permission === 'granted' && document.visibilityState !== 'visible') {
        new Notification(c.to === 'up' ? 'TapCard · Pulih' : 'TapCard · Gangguan', { body: text, tag: `uptime-${p.monitor.id}`, icon: '/icons/panel-192.png' })
      }
      if (c.to === 'down') beep()
    },
    async askPermission() {
      if (this.permission === 'unsupported') return
      this.permission = await Notification.requestPermission()
    },
  },
})

/** Dua nada pendek (Web Audio) — tanpa file suara. Diam bila browser belum mengizinkan audio. */
function beep() {
  try {
    const ctx = new AudioContext()
    ;[880, 660].forEach((f, i) => {
      const o = ctx.createOscillator()
      const g = ctx.createGain()
      o.frequency.value = f
      g.gain.setValueAtTime(0.08, ctx.currentTime + i * 0.18)
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + i * 0.18 + 0.16)
      o.connect(g).connect(ctx.destination)
      o.start(ctx.currentTime + i * 0.18)
      o.stop(ctx.currentTime + i * 0.18 + 0.17)
    })
    setTimeout(() => ctx.close(), 600)
  } catch { /* abaikan */ }
}
