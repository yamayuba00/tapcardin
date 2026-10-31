// TRD §8.3 — scan QR dengan BarcodeDetector native.
export const qrSupported = async () =>
  'BarcodeDetector' in window && (await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')

/** @param {HTMLVideoElement} video */
export async function scanQr(video, { signal } = {}) {
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
  video.srcObject = stream
  await video.play()
  const detector = new window.BarcodeDetector({ formats: ['qr_code'] })
  try {
    while (!signal?.aborted) {
      const codes = await detector.detect(video)
      if (codes.length) return codes[0].rawValue
      await new Promise((r) => setTimeout(r, 200))
    }
    return null
  } finally {
    stream.getTracks().forEach((t) => t.stop())
  }
}
