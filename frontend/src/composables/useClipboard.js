import { toastSuccess, toastError } from '@/composables/useConfirm'

// Copy arbitrary text to the clipboard with toast feedback. Falls back to the
// old execCommand trick when navigator.clipboard is unavailable (e.g. non-HTTPS).
export async function copyToClipboard(text, successMessage = 'Copied to clipboard') {
  if (!text) return false
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      const input = document.createElement('textarea')
      input.value = text
      input.style.position = 'fixed'
      input.style.opacity = '0'
      document.body.appendChild(input)
      input.select()
      document.execCommand('copy')
      document.body.removeChild(input)
    }
    toastSuccess(successMessage)
    return true
  } catch {
    toastError('Could not copy the link.')
    return false
  }
}

export function useClipboard() {
  return { copy: copyToClipboard }
}
