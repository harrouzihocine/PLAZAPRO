// The one place a message becomes a one-line label (inbox previews, reply
// banners, quote excerpts). Mirrors the backend's Message::previewLabel().
export function messagePreview(message) {
  if (!message) return ''
  if (message.redacted) return 'Message deleted'
  return (
    {
      image: '📷 Photo',
      voice: '🎤 Voice note',
      file: '📎 File',
    }[message.type] ?? (message.body || 'New message')
  )
}
