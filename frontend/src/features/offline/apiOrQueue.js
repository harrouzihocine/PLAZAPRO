import { useApi } from '@/composables/useApi'
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'
import { toastInfo } from '@/composables/useConfirm'

// The write path for offline-queueable actions (the field-agent set).
//
//   online          → straight through, ALREADY carrying the idempotency key
//                     (a response lost mid-flight is replay-safe);
//   offline / died  → into the outbox, replayed FIFO on reconnect;
//   real 4xx/5xx    → thrown to the caller — the existing mutate/toast path
//                     (and the server's guards) stay the authority.
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
  uuid = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`,
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
      headers: { 'X-Idempotency-Key': uuid },
    })
    return { queued: false, data, uuid }
  } catch (e) {
    if (!e.response && e.code !== 'ERR_CANCELED') return enqueue()
    throw e
  }
}

function buildPayload(files, body) {
  if (!files?.length) return body
  const form = new FormData()
  for (const [k, v] of Object.entries(body ?? {})) {
    if (v !== null && v !== undefined) form.append(k, String(v))
  }
  for (const f of files) form.append(f.field, f.blob, f.name || 'attachment')
  return form
}
