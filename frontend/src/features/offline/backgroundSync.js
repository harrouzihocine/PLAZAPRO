// One-shot Background Sync registration for the outbox: the service worker's
// 'plaza-outbox' sync event fires when connectivity returns — even if the app
// was closed in the meantime (sw.js replays the queue itself then; with a live
// page it hands over via postMessage so the Sync Center UI stays authoritative).
// No-op where SyncManager is unsupported (old WebViews, Firefox, dev server):
// the in-app reconnect watcher still replays on the next open.
export async function registerOutboxSync() {
  try {
    if (!navigator.serviceWorker?.controller) return
    const reg = await navigator.serviceWorker.ready
    await reg.sync?.register('plaza-outbox')
  } catch {
    /* denied/unsupported — in-app replay covers it */
  }
}
