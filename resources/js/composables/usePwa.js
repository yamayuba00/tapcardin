// PWA: daftarkan service worker (hanya bila shell menyertakan manifest — yaitu host aplikasi) dan kelola
// ajakan "Pasang aplikasi". Chrome/Android/desktop memakai event beforeinstallprompt; iOS Safari tidak punya API
// pemasangan, jadi ditampilkan petunjuk "Bagikan → Tambahkan ke Layar Utama".
import { computed, ref } from 'vue'

const deferred = ref(null)
const installed = ref(false)
const dismissedKey = 'tapcard:pwa-dismissed'
const dismissed = ref(readDismissed())

function readDismissed() {
  try { return localStorage.getItem(dismissedKey) === '1' } catch { return false }
}

const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
const ios = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent) && !/crios|fxios/i.test(window.navigator.userAgent)

export function initPwa() {
  if (!document.querySelector('link[rel="manifest"]')) return
  installed.value = standalone()
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    deferred.value = e
  })
  window.addEventListener('appinstalled', () => {
    installed.value = true
    deferred.value = null
  })
  if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
  }
}

export function usePwa() {
  const canPrompt = computed(() => !!deferred.value)
  const iosHint = computed(() => !installed.value && !deferred.value && ios())
  const available = computed(() => !installed.value && (canPrompt.value || iosHint.value))

  async function install() {
    if (!deferred.value) return false
    deferred.value.prompt()
    const { outcome } = await deferred.value.userChoice
    deferred.value = null
    return outcome === 'accepted'
  }
  function dismiss() {
    dismissed.value = true
    try { localStorage.setItem(dismissedKey, '1') } catch { /* mode privat */ }
  }
  return { available, canPrompt, iosHint, installed, dismissed, install, dismiss }
}
