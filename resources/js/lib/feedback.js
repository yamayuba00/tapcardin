// DBD §13 — Reader & Encode: teks + suara + getaran selain warna (Web Audio API, tanpa file audio).
let ctx
export function beep(ok = true) {
  try {
    ctx ??= new (window.AudioContext || window.webkitAudioContext)()
    const o = ctx.createOscillator()
    const g = ctx.createGain()
    o.frequency.value = ok ? 880 : 220
    g.gain.value = 0.08
    o.connect(g).connect(ctx.destination)
    o.start()
    o.stop(ctx.currentTime + (ok ? 0.12 : 0.35))
  } catch { /* audio tidak tersedia */ }
  navigator.vibrate?.(ok ? 60 : [80, 60, 80])
}
