// Helper gerak landing page. Fungsi murni (tanpa window/document saat import) → aman di SSR dan mudah diuji.
const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi)

/** true bila pengguna meminta gerak dikurangi. Hanya dipanggil dari onMounted / direktif mounted. */
export const prefersReducedMotion = () =>
  typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

/** Kurva easing keluar (cepat di awal, melambat di akhir). t dijepit ke 0..1. */
export const easeOutCubic = (t) => 1 - (1 - clamp(t, 0, 1)) ** 3

/** Nilai bilangan bulat antara from dan to pada progres t (0..1) dengan easing keluar. */
export const tweenValue = (from, to, t) => Math.round(from + (to - from) * easeOutCubic(t))

/**
 * Kemiringan kartu dari posisi pointer di dalam kotak (px dari pojok kiri-atas).
 * Hasil dalam derajat, dijepit ke ±max. Ukuran nol → tanpa kemiringan.
 */
export function tiltFromPointer(px, py, width, height, max = 6) {
  if (!width || !height) return { rx: 0, ry: 0 }
  const nx = clamp((px / width) * 2 - 1, -1, 1)
  const ny = clamp((py / height) * 2 - 1, -1, 1)
  return { rx: round(-ny * max), ry: round(nx * max) }
}

const round = (v) => Math.round(v * 100) / 100 + 0 // + 0: tanpa -0
