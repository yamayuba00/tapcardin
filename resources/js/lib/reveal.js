// Direktif v-reveal: sekali fade/slide saat elemen masuk viewport (IntersectionObserver).
// Kelas `reveal-pending` baru ditambahkan di mounted (hanya klien) → HTML server tetap tampil penuh tanpa JS.
// Dengan prefers-reduced-motion atau tanpa IntersectionObserver, elemen tidak disembunyikan sama sekali.
import { prefersReducedMotion } from '@/lib/motion'

export const vReveal = {
  mounted(el) {
    if (typeof IntersectionObserver === 'undefined' || prefersReducedMotion()) return
    el.classList.add('reveal-pending')
    const io = new IntersectionObserver((entries) => {
      if (!entries.some((e) => e.isIntersecting)) return
      io.disconnect()
      el.classList.remove('reveal-pending')
      el.classList.add('reveal-in')
    }, { threshold: 0.1, rootMargin: '0px 0px -8% 0px' })
    io.observe(el)
    el.__reveal = io
  },
  unmounted(el) {
    el.__reveal?.disconnect()
  },
}
