<script setup>
import BrandLogo from '@/components/common/BrandLogo/index.vue'
import { config } from '@/config'

const year = new Date().getFullYear()
</script>

<template>
  <footer class="border-t border-line">
    <div class="mx-auto grid max-w-[1200px] gap-8 px-4 py-12 sm:grid-cols-[1.4fr_1fr_1fr] sm:px-6">
      <div class="grid content-start gap-3">
        <BrandLogo />
        <p class="max-w-xs text-sm text-muted">Kartu nama NFC & QR dengan bio link yang selalu terbaru. Satu sentuhan, langsung terhubung.</p>
      </div>
      <nav class="grid content-start gap-2 text-sm" aria-label="Produk">
        <span class="label">Produk</span>
        <a href="#template" class="text-muted no-underline hover:text-fg">Template kartu</a>
        <a href="#bio-link" class="text-muted no-underline hover:text-fg">Bio link</a>
        <a href="#harga" class="text-muted no-underline hover:text-fg">Harga</a>
      </nav>
      <nav class="grid content-start gap-2 text-sm" aria-label="Akun">
        <span class="label">Akun</span>
        <a :href="`${config.appUrl}/login`" class="text-muted no-underline hover:text-fg">Masuk / Daftar</a>
        <a :href="`${config.resolverUrl}/k`" class="text-muted no-underline hover:text-fg">Masukkan kode kartu</a>
        <a href="#faq" class="text-muted no-underline hover:text-fg">Bantuan</a>
      </nav>
    </div>
    <p class="border-t border-line px-4 py-6 text-center text-xs text-muted">© {{ year }} TapCard. Dibuat di Indonesia.</p>
  </footer>
</template>
