// Kode pasangan disimpan di berkas konfigurasi pengguna agar tetap sama setelah konektor dijalankan ulang.
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { dirname, join } from 'node:path'
import { generatePairingCode } from './protocol.js'

export const defaultConfigPath = () => join(homedir(), '.tapcard-nfc-bridge.json')

export function loadPairingCode(file = defaultConfigPath(), { reset = false } = {}) {
  if (!reset && existsSync(file)) {
    try {
      const { code } = JSON.parse(readFileSync(file, 'utf8'))
      if (/^\d{6}$/.test(code)) return code
    } catch { /* berkas rusak → buat baru */ }
  }
  const code = generatePairingCode()
  mkdirSync(dirname(file), { recursive: true })
  writeFileSync(file, JSON.stringify({ code }) + '\n', { mode: 0o600 })
  return code
}
