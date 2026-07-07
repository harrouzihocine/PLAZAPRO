// One uuid source for the offline layer. These ids double as idempotency keys
// (backend App\Http\Middleware\IdempotencyKey accepts UUIDs only), so every
// producer must mint the same format — the fallback covers old WebViews
// without crypto.randomUUID and stays a valid v4 UUID.
export function newUuid() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}
