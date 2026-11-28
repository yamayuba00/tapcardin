# Konektor NFC USB TapCard

Aplikasi kecil yang berjalan di PC operator produksi. Konektor ini menghubungkan **Stasiun encode** di panel
(`/encode-station`, mode **Reader USB**) dengan reader NFC USB/USB-C (PC/SC). Alurnya sama dengan mode HP (Web NFC):

1. tulis URL kartu sebagai record NDEF URI,
2. baca ulang dan cocokkan,
3. kunci permanen (opsional, hanya bila konektor dijalankan dengan `--allow-lock`),
4. halaman panel melaporkan UID ke server.

Konektor hanya mendengarkan `ws://127.0.0.1:47321`. Konektor tidak pernah membuka port ke jaringan.

## Reader yang didukung

| Model | Status | Catatan |
| --- | --- | --- |
| ACS ACR122U | Didukung | Driver PC/SC bawaan Windows/macOS/Linux sering cukup; pasang driver ACS bila tidak terdeteksi. |
| ACS ACR1252U (USB / USB-C) | Didukung | Muncul sebagai 2 reader: **PICC** (antena) dan **SAM**. Konektor otomatis memakai PICC. |
| Reader PC/SC lain | Kemungkinan | Harus mendukung pseudo-APDU `FF CA` (UID), `FF B0` (baca) dan `FF D6` (tulis) untuk tag Type 2. |

Kartu: **NTAG213 / NTAG215 / NTAG216** yang sudah berformat NDEF (CC `E1 10 …`). Kapasitas dicek dari CC:
144 / 496 / 872 byte.

## Kebutuhan

- Node.js 20 LTS atau lebih baru (disarankan 20 atau 22 LTS).
- Paket `nfc-pcsc` memakai modul native `@pokusew/pcsclite`, yang dibangun saat `npm install`:
  - **Windows**: Python 3 dan *Visual Studio Build Tools* dengan beban kerja "Desktop development with C++".
    Layanan **Smart Card** (`SCardSvr`) harus berjalan.
  - **macOS**: Xcode Command Line Tools (`xcode-select --install`). PC/SC sudah bawaan.
  - **Linux (Debian/Ubuntu)**: `sudo apt install build-essential python3 libpcsclite-dev pcscd`, lalu
    `sudo systemctl enable --now pcscd`. Untuk ACR122U, blacklist modul kernel `pn533_usb`/`nfc`
    (`/etc/modprobe.d/blacklist-nfc.conf`) supaya reader tidak direbut driver NFC kernel.

## Pemasangan

```bash
cd tools/nfc-bridge
npm install
```

## Menjalankan

```bash
npm start
# atau, bila chip boleh dikunci permanen dari panel:
node bin/nfc-bridge.js --allow-lock
```

Variabel lingkungan (opsional):

| Variabel | Bawaan | Fungsi |
| --- | --- | --- |
| `NFC_BRIDGE_PORT` | `47321` | Port WebSocket di 127.0.0.1. Bila diubah, set juga `localStorage['tapcard.nfcBridgePort']` di browser. |
| `NFC_BRIDGE_ORIGINS` | – | Origin panel tambahan, dipisah koma, mis. `https://panel.tapcard.id`. `http(s)://panel.localhost:8000` selalu diizinkan. |
| `NFC_BRIDGE_ALLOW_LOCK` | – | `1` = sama dengan `--allow-lock`. |
| `NFC_BRIDGE_READER` | – | Pilih reader yang namanya memuat teks ini (bila ada beberapa reader). |
| `NFC_BRIDGE_CONFIG` | `~/.tapcard-nfc-bridge.json` | Berkas penyimpan kode pasangan. |

Contoh produksi (PowerShell):

```powershell
$env:NFC_BRIDGE_ORIGINS = "https://panel.tapcard.id"; npm start
```

## Memasangkan (pairing)

1. Jalankan konektor. Konsol menampilkan **KODE PASANGAN** 6 angka.
2. Buka panel → **Stasiun encode** → pilih **Reader USB**.
3. Masukkan kode tersebut, lalu tekan **Pasangkan**. Kode disimpan di browser itu; tidak perlu diulang.
4. Status berubah menjadi *Terhubung · Reader: …*. Letakkan kartu: *Kartu terdeteksi*.

Kode tetap sama setelah konektor dijalankan ulang. Untuk membuat kode baru (mis. PC dipakai orang lain):
`node bin/nfc-bridge.js --reset-pairing`. Browser yang lama akan diminta kode lagi.

## Keamanan

- Hanya mendengarkan `127.0.0.1`. `NFC_BRIDGE_HOST` selain itu ditolak.
- Handshake ditolak (HTTP 403) bila header `Origin` tidak ada di allowlist, atau header `Host` bukan
  `127.0.0.1:<port>`/`localhost:<port>` (mencegah DNS rebinding).
- Semua perintah selain `auth` ditolak sebelum kode pasangan benar. Maksimal 5 kode salah per menit.
- `write` hanya menerima URL `http(s)` yang memuat `/c/<token>` dari token yang sama, maksimal 300 karakter.
- Penguncian permanen butuh **dua** tanda: konektor dijalankan dengan `--allow-lock`, dan operator mencentang
  "Kunci chip permanen" di panel.

## Protokol WebSocket (JSON)

| Arah | Pesan |
| --- | --- |
| server → klien | `{type:'hello', version:1, canLock}` saat tersambung |
| klien → server | `{type:'auth', id, code:'123456'}` → `{type:'auth', id, ok, error?}` lalu `status` |
| klien → server | `{type:'status', id}` → `{type:'status', id, reader: nama\|null, card: bool, canLock}` |
| klien → server | `{type:'write', id, url, token, lock?}` → `{type:'progress', id, text}`… lalu `{type:'result', id, ok:true, uid, model, locked}` atau `{type:'result', id, ok:false, error}` |
| server → klien | `{type:'reader', name}` (reader dicolok/dicabut, `name:null`), `{type:'card', present, uid}` |
| server → klien | `{type:'error', id, error, code?}` (`code:'unpaired'` bila belum dipasangkan) |

Bila tidak ada kartu saat `write`, konektor menunggu hingga 20 detik.

## APDU yang dipakai

- UID: `FF CA 00 00 00`
- Baca 4 halaman: `FF B0 00 <halaman> 10`
- Tulis 1 halaman: `FF D6 00 <halaman> 04 <4 byte>` mulai halaman 4 (TLV `03 <len> D1 01 <len> 55 04 … FE`)
- Kunci (hanya dengan `--allow-lock`): CC halaman 3 byte 3 → `0F`, dynamic lock (`0x28`/`0x82`/`0xE2`)
  → `FF FF FF 00`, lalu static lock halaman 2 → `FF FF`. **Permanen, tidak bisa dibatalkan.**

## Uji

```bash
npm test
```

Uji memakai tag NTAG tiruan dan tidak butuh reader. Uji server butuh paket `ws` (`npm install`).

## Pemecahan masalah

- **"Modul nfc-pcsc belum terpasang atau gagal dibangun"**: jalankan ulang `npm install` dan baca galat
  node-gyp. Biasanya Python atau Build Tools C++ belum terpasang, atau versi Node terlalu baru untuk modul native.
  Coba Node 20/22 LTS.
- **Panel: "Tidak terhubung"**: konektor belum berjalan, port berbeda, atau origin panel belum ada di
  `NFC_BRIDGE_ORIGINS`. Konsol mencatat `tolak: origin …` bila origin ditolak.
- **Reader tidak ditemukan**:
  - Windows: pastikan layanan *Smart Card* berjalan (`services.msc` → Smart Card → Automatic). Pasang driver
    resmi ACS bila Device Manager menunjukkan perangkat tak dikenal. Cabut-colok reader.
  - Linux: `pcsc_scan` (paket `pcsc-tools`) harus menampilkan reader. Bila tidak, cek `pcscd` dan blacklist `pn533`.
  - macOS: cabut-colok reader; ACR122U kadang butuh driver ACS di macOS lama.
- **"Kartu belum berformat NDEF"**: kartu bukan NTAG21x atau CC kosong. Format dulu sebagai NDEF (mis. dengan
  aplikasi NFC Tools) atau pakai kartu NTAG baru.
- **"Kartu terlepas…"**: kartu bergeser. Letakkan diam di tengah reader sampai layar hijau.
- **"Kartu sudah dikunci permanen"**: chip tidak bisa ditulis ulang. Pakai kartu lain.
- **Port sudah dipakai**: konektor lain masih berjalan, atau atur `NFC_BRIDGE_PORT`.
