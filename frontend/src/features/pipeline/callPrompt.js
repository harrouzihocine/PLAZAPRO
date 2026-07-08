import Swal from 'sweetalert2'
import { BASE_SWAL_OPTS } from '@/composables/useConfirm'
import { pipelineApi } from '@/features/pipeline/api'
import router from '@/router'

// The cross-device "log this call?" prompt. After the phone reports the dialer
// was opened (a click-to-call handoff), every open session receives a
// kind=call_log_prompt notification — this turns it into the actual dialog:
//   Log the call → deep-link to the ?logcall page (the modal auto-opens there);
//   Not now      → the request is dismissed everywhere;
//   Esc/outside  → decide later (the bell entry keeps the link).
// The call-request.closed broadcast closes a prompt another device answered.

let openId = null

export async function promptCallLog({ id, title, body, link }) {
  const requestId = Number(id)
  if (!requestId || openId === requestId) return

  openId = requestId
  const result = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title,
    text: body || 'Need to log this phone call?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Log the call',
    cancelButtonText: 'Not now',
    reverseButtons: true,
    customClass: {
      confirmButton: 'plaza-swal-confirm',
      cancelButton: 'plaza-swal-cancel',
    },
  })
  if (openId === requestId) openId = null

  if (result.isConfirmed && link) {
    router.push(link)
  } else if (result.dismiss === Swal.DismissReason.cancel) {
    pipelineApi.closeCallRequest(requestId, 'dismissed').catch(() => {})
  }
}

// Another device answered this request — drop the still-open prompt here.
export function closeCallLogPrompt(callRequestId) {
  if (openId === Number(callRequestId)) {
    openId = null
    Swal.close()
  }
}
