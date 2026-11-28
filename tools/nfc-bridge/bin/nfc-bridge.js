#!/usr/bin/env node
// Konektor NFC USB TapCard. Pemakaian: node bin/nfc-bridge.js [--allow-lock] [--reset-pairing]
import { WebSocketServer } from 'ws'
import { loadPairingCode, defaultConfigPath } from '../src/pairing.js'
import { createPcscTransport } from '../src/pcsc.js'
import { BIND_HOST, parseOrigins, parsePort } from '../src/protocol.js'
import { createBridgeServer } from '../src/server.js'

const args = new Set(process.argv.slice(2))
const env = process.env
const log = (m) => console.log(`[${new Date().toLocaleTimeString('id-ID')}] ${m}`)

if (env.NFC_BRIDGE_HOST && env.NFC_BRIDGE_HOST !== BIND_HOST) {
  console.error(`NFC_BRIDGE_HOST=${env.NFC_BRIDGE_HOST} ditolak: konektor hanya mendengarkan ${BIND_HOST}.`)
  process.exit(1)
}

const port = parsePort(env.NFC_BRIDGE_PORT)
const origins = parseOrigins(env.NFC_BRIDGE_ORIGINS)
const allowLock = args.has('--allow-lock') || env.NFC_BRIDGE_ALLOW_LOCK === '1'
const configFile = env.NFC_BRIDGE_CONFIG || defaultConfigPath()
const pairingCode = loadPairingCode(configFile, { reset: args.has('--reset-pairing') })

let transport
try {
  transport = createPcscTransport({ readerFilter: env.NFC_BRIDGE_READER || '', log })
} catch (e) {
  console.error(e.message)
  process.exit(1)
}

const server = createBridgeServer({ WebSocketServer, port, origins, pairingCode, transport, allowLock, log })
server.wss.on('listening', () => {
  console.log('')
  console.log('  Konektor NFC TapCard berjalan')
  console.log(`  Alamat      : ws://${BIND_HOST}:${port}`)
  console.log(`  Origin izin : ${[...origins].join(', ')}`)
  console.log(`  Kunci chip  : ${allowLock ? 'DIIZINKAN (permanen!)' : 'nonaktif (tambahkan --allow-lock)'}`)
  console.log('')
  console.log(`  KODE PASANGAN: ${pairingCode}`)
  console.log('  Masukkan kode ini sekali di Stasiun encode (mode Reader USB).')
  console.log('')
  log('menunggu reader NFC…')
})
server.wss.on('error', (e) => {
  console.error(e.code === 'EADDRINUSE' ? `Port ${port} sudah dipakai. Konektor lain masih berjalan? Atau atur NFC_BRIDGE_PORT.` : e.message)
  process.exit(1)
})
const stop = () => { server.close().finally(() => { transport.close?.(); process.exit(0) }) }
process.on('SIGINT', stop)
process.on('SIGTERM', stop)
