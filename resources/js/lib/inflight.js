// Panggilan async yang sama dan sedang berjalan dipakai bersama — mis. router guard & halaman memanggil /api/me bersamaan.

/** @template T @param {() => Promise<T>} fn @returns {() => Promise<T>} */
export function shareInflight(fn) {
  let pending = null
  return () => (pending ??= Promise.resolve().then(fn).finally(() => (pending = null)))
}
