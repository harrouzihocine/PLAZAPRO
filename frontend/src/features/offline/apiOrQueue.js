import { useApi } from '@/composables/useApi'
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'
import { toastInfo } from '@/composables/useConfirm'
import { newUuid } from '@/utils/uuid'

// The write path for offline-queueable actions (the field-agent set).
//
//   online          → straight through, ALREADY carrying the idempotency key
//                     (a response lost mid-flight is replay-safe);
//   offline / died  → into the outbox, replayed FIFO on reconnect;
//   real 4xx/5xx    → thrown to the caller — the existing mutate/toast path
//                     (and the server's guards) stay the authority.
//
// `ledger: false` skips the online idempotency header for naturally
// last-write-wins cursor writes (read-marks) — they fire constantly and a
// server ledger row per mark would be pure write amplification. Queued
// replays still carry the key (rare, and replay safety matters there).
//
// Returns { queued: true, uuid } or { queued: false, data, uuid }.
export async function queueable({
  method = 'post',
  url,
  body = null,
  files = [],
  label,
  entityHint = {},
  silent = false,
  ledger = true,
  uuid = newUuid(),
  queuedToast = 'Saved offline — it will sync when you reconnect.',
}) {
  const enqueue = async () => {
    await useOutboxStore().enqueue({ uuid, method, url, body, files, label, entityHint, silent })
    if (!silent && queuedToast) toastInfo(queuedToast)
    return { queued: true, uuid }
  }

  if (!useNetworkStore().online) return enqueue()

  try {
    const { data } = await useApi().request({
      method,
      url,
      data: buildPayload(files, body),
      headers: ledger ? { 'X-Idempotency-Key': uuid } : {},
    })
    return { queued: false, data, uuid }
  } catch (e) {
    if (!e.response && e.code !== 'ERR_CANCELED') return enqueue()
    throw e
  }
}

// Shared by the online path above AND the outbox replay — one body builder,
// so a field added to one path can never silently miss the other.
export function buildPayload(files, body) {
  if (!files?.length) return body
  const form = new FormData()
  for (const [k, v] of Object.entries(body ?? {})) {
    if (v !== null && v !== undefined) form.append(k, String(v))
  }
  for (const f of files) form.append(f.field, f.blob, f.name || 'attachment')
  return form
}
