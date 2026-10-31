import { ref } from 'vue'

// DBD §3.7 — gelap default; terang via toggle, disimpan per perangkat.
const KEY = 'tc-theme'
const theme = ref('dark')

function read() {
  try { return localStorage.getItem(KEY) } catch { return null }
}

export function applyStoredTheme() {
  theme.value = read() || 'dark'
  document.documentElement.dataset.theme = theme.value
}

/** Paksa tema sementara (mis. profil publik mengikuti template kartu) tanpa menyimpannya. */
export function setTemporaryTheme(mode) {
  document.documentElement.dataset.theme = mode
}

export function useTheme() {
  const toggle = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    document.documentElement.dataset.theme = theme.value
    try { localStorage.setItem(KEY, theme.value) } catch { /* mode privat */ }
  }
  return { theme, toggle }
}
