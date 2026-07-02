import Swal from 'sweetalert2'

// App-wide replacement for native alert()/confirm(). Buttons/popup are themed via
// swal.css using the same CSS tokens as the rest of the app (light/dark aware).

// Ask the user to confirm an action. Resolves to true only when confirmed.
export function confirmAction({
  title = 'Are you sure?',
  text = '',
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  danger = false,
} = {}) {
  return Swal.fire({
    title,
    text,
    icon: danger ? 'warning' : 'question',
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    reverseButtons: true,
    focusCancel: true,
    customClass: {
      confirmButton: danger ? 'plaza-swal-confirm plaza-swal-danger' : 'plaza-swal-confirm',
      cancelButton: 'plaza-swal-cancel',
    },
  }).then((result) => result.isConfirmed)
}

// Show a simple informational/error message (replaces alert()).
export function alertMessage({ title = '', text = '', icon = 'info' } = {}) {
  return Swal.fire({
    title,
    text,
    icon,
    confirmButtonText: 'OK',
    customClass: { confirmButton: 'plaza-swal-confirm' },
  })
}

// Non-blocking toast anchored to the top-right corner. Used for flash
// success/error feedback instead of inline messages scattered through views.
const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 4000,
  timerProgressBar: true,
  customClass: { popup: 'plaza-swal-toast' },
})

export function showToast(text, icon = 'success') {
  if (!text) return
  return Toast.fire({ icon, title: text })
}

export function toastSuccess(text) {
  return showToast(text, 'success')
}

export function toastError(text) {
  return showToast(text || 'Something went wrong.', 'error')
}

export function toastInfo(text) {
  return showToast(text, 'info')
}

export function useConfirm() {
  return { confirm: confirmAction, alert: alertMessage }
}

export function useToast() {
  return { toast: showToast, success: toastSuccess, error: toastError, info: toastInfo }
}
