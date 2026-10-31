// Undo/redo berbasis snapshot untuk Design Studio (TRD §4.2 studio/history.js).

/**
 * @template T
 * @param {T} initial
 * @param {number} [limit]
 */
export function createHistory(initial, limit = 100) {
  const clone = (v) => JSON.parse(JSON.stringify(v))
  let past = []
  let present = clone(initial)
  let future = []

  return {
    get present() { return clone(present) },
    get canUndo() { return past.length > 0 },
    get canRedo() { return future.length > 0 },
    /** Simpan keadaan baru; diabaikan bila identik dengan keadaan sekarang. */
    push(state) {
      const next = clone(state)
      if (JSON.stringify(next) === JSON.stringify(present)) return false
      past.push(present)
      if (past.length > limit) past.shift()
      present = next
      future = []
      return true
    },
    undo() {
      if (!past.length) return clone(present)
      future.push(present)
      present = past.pop()
      return clone(present)
    },
    redo() {
      if (!future.length) return clone(present)
      past.push(present)
      present = future.pop()
      return clone(present)
    },
    reset(state) {
      past = []
      future = []
      present = clone(state)
    },
  }
}
