<script setup>
// Daftar pintasan keyboard editor (tombol ? di bilah atas, atau tekan "?").
import AppModal from '@/components/ui/AppModal/index.vue'

defineProps({ lanyard: Boolean })
const emit = defineEmits(['close'])
const GROUPS = [
  ['Tambah elemen', [['T', 'Teks'], ['R', 'Kotak'], ['O', 'Elips'], ['L', 'Garis']]],
  ['Pilih & ubah', [
    ['Klik / Shift+klik', 'Pilih satu / tambah ke seleksi'], ['Ctrl+A', 'Pilih semua'], ['Esc', 'Batal pilih'],
    ['Panah', 'Geser 0,1 mm'], ['Shift+Panah', 'Geser 1 mm'], ['Alt saat menyeret', 'Geser tanpa menempel'],
    ['Shift saat mengubah ukuran', 'Rasio tetap'], ['Shift saat memutar', 'Kelipatan 15°'], ['Delete', 'Hapus'],
  ]],
  ['Atur', [
    ['Ctrl+D', 'Duplikat'], ['Ctrl+G / Ctrl+Shift+G', 'Kelompokkan / pisahkan'], ['Ctrl+L', 'Kunci / buka kunci'],
    ['Ctrl+] / Ctrl+[', 'Maju / mundur satu lapis'], ['Ctrl+Shift+] / [', 'Paling depan / belakang'],
  ]],
  ['Edit', [['Ctrl+Z', 'Urungkan'], ['Ctrl+Y / Ctrl+Shift+Z', 'Ulangi'], ['Ctrl+C / X / V', 'Salin / potong / tempel (antar kartu & lanyard)'], ['Ctrl+S', 'Simpan']]],
  ['Tampilan', [['+ / − / 0', 'Perbesar / perkecil / pas layar'], ['Seret dari penggaris', 'Buat garis bantu (seret kembali ke penggaris untuk menghapus)'], ['?', 'Daftar pintasan ini']]],
]
</script>

<template>
  <AppModal size="md" title="Pintasan keyboard" @close="emit('close')">
    <div class="grid gap-5">
      <section v-for="[title, rows] in GROUPS" :key="title" class="grid gap-2">
        <h3 class="label">{{ title }}</h3>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-sm">
          <template v-for="[keys, what] in rows" :key="keys">
            <dt><kbd class="rounded-sm border border-line bg-surface-2 px-1.5 py-0.5 font-mono text-xs">{{ keys }}</kbd></dt>
            <dd class="text-muted">{{ what }}</dd>
          </template>
        </dl>
      </section>
    </div>
  </AppModal>
</template>
