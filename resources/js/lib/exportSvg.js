// Ekspor SVG kanvas desain → PNG (pratinjau cetak). Gambar & font lokal disematkan sebagai data URL agar hasilnya
// sama dengan editor; warna dari kelas Tailwind disalin ke atribut karena PNG tidak membawa CSS halaman.

const FONT_FILES = {
  'Syne-600': '/fonts/syne-600.woff2', 'Syne-700': '/fonts/syne-700.woff2', 'Syne-800': '/fonts/syne-800.woff2',
  'IBM Plex Sans-400': '/fonts/ibm-plex-sans-400.woff2', 'IBM Plex Sans-500': '/fonts/ibm-plex-sans-500.woff2', 'IBM Plex Sans-600': '/fonts/ibm-plex-sans-600.woff2',
  'IBM Plex Mono-400': '/fonts/ibm-plex-mono-400.woff2', 'IBM Plex Mono-500': '/fonts/ibm-plex-mono-500.woff2',
}

async function toDataUrl(url) {
  const res = await fetch(url, { credentials: 'same-origin' })
  const blob = await res.blob()
  return new Promise((resolve, reject) => {
    const r = new FileReader()
    r.onload = () => resolve(r.result)
    r.onerror = reject
    r.readAsDataURL(blob)
  })
}

async function fontCss(svg) {
  const used = new Set()
  svg.querySelectorAll('[font-family]').forEach((el) => used.add(`${el.getAttribute('font-family')}-${el.getAttribute('font-weight') || 400}`))
  const rules = await Promise.all([...used].filter((k) => FONT_FILES[k]).map(async (k) => {
    const [family, weight] = [k.slice(0, k.lastIndexOf('-')), k.slice(k.lastIndexOf('-') + 1)]
    return `@font-face{font-family:"${family}";font-weight:${weight};src:url(${await toDataUrl(FONT_FILES[k])}) format("woff2");}`
  }))
  return rules.join('')
}

/**
 * @param {SVGSVGElement} svg
 * @param {{ widthPx?: number, background?: string }} opts
 * @returns {Promise<Blob>}
 */
export async function svgToPng(svg, { widthPx = 1200 } = {}) {
  const canvas = await svgToCanvas(svg, widthPx)
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
}

/**
 * Beberapa sisi kartu → satu PNG (bertumpuk vertikal, sudut membulat seperti kartu asli, latar transparan).
 * @param {SVGSVGElement[]} svgs
 * @param {{ widthPx?: number, gap?: number, radius?: number }} opts radius = rasio terhadap lebar (kartu CR80 ≈ 0.037)
 * @returns {Promise<Blob>}
 */
export async function cardsToPng(svgs, { widthPx = 1400, gap = 48, radius = 0.037 } = {}) {
  const sides = await Promise.all(svgs.map((svg) => svgToCanvas(svg, widthPx)))
  const out = document.createElement('canvas')
  out.width = widthPx
  out.height = sides.reduce((h, c) => h + c.height, 0) + gap * (sides.length - 1)
  const ctx = out.getContext('2d')
  const r = widthPx * radius
  let y = 0
  for (const c of sides) {
    ctx.save()
    ctx.beginPath()
    ctx.roundRect(0, y, c.width, c.height, r)
    ctx.clip()
    ctx.drawImage(c, 0, y)
    ctx.restore()
    y += c.height + gap
  }
  return new Promise((resolve) => out.toBlob(resolve, 'image/png'))
}

async function svgToCanvas(svg, widthPx) {
  const clone = svg.cloneNode(true)
  // salin warna hasil CSS (kelas Tailwind) ke atribut
  const src = svg.querySelectorAll('*')
  clone.querySelectorAll('*').forEach((el, i) => {
    if (!el.getAttribute('class')) return
    const cs = getComputedStyle(src[i])
    for (const p of ['fill', 'stroke', 'stroke-opacity', 'fill-opacity', 'color']) {
      if (!el.getAttribute(p) && cs.getPropertyValue(p)) el.setAttribute(p, cs.getPropertyValue(p))
    }
    el.removeAttribute('class')
  })
  await Promise.all([...clone.querySelectorAll('image')].map(async (img) => {
    const href = img.getAttribute('href')
    if (href && !href.startsWith('data:')) img.setAttribute('href', await toDataUrl(href).catch(() => href))
  }))
  const style = document.createElementNS('http://www.w3.org/2000/svg', 'style')
  style.textContent = await fontCss(clone)
  clone.insertBefore(style, clone.firstChild)

  const [, , vw, vh] = (clone.getAttribute('viewBox') || '0 0 100 100').split(/\s+/).map(Number)
  const h = Math.round((widthPx * vh) / vw)
  clone.setAttribute('width', widthPx)
  clone.setAttribute('height', h)
  const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml' }))
  try {
    const img = new Image()
    img.decoding = 'async'
    await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = reject; img.src = url })
    const canvas = document.createElement('canvas')
    canvas.width = widthPx
    canvas.height = h
    canvas.getContext('2d').drawImage(img, 0, 0, widthPx, h)
    return canvas
  } finally {
    URL.revokeObjectURL(url)
  }
}

export function download(blob, filename) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = filename
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 2000)
}
