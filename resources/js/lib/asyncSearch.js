// Pencarian ke server untuk pemilih: tunda ketikan (debounce) & abaikan jawaban lama yang datang belakangan.

/**
 * @param {(q: string) => Promise<any>} fetcher
 * @param {{ delay?: number, onStart?: () => void, onResult: (value: any, q: string) => void, onError?: (e: unknown) => void }} handlers
 */
export function createAsyncSearch(fetcher, { delay = 250, onStart, onResult, onError }) {
  let seq = 0
  let timer = null

  async function now(q) {
    clearTimeout(timer)
    const id = ++seq
    onStart?.()
    try {
      const value = await fetcher(q)
      if (id === seq) onResult(value, q)
    } catch (e) {
      if (id === seq) onError?.(e)
    }
  }

  return {
    /** jalankan setelah jeda ketik */
    run(q) {
      clearTimeout(timer)
      timer = setTimeout(() => now(q), delay)
    },
    /** jalankan segera (mis. saat panel dibuka) */
    now,
    /** batalkan jeda & buang jawaban yang sedang ditunggu */
    cancel() {
      clearTimeout(timer)
      seq++
    },
  }
}
