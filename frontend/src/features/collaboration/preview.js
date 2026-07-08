import { t } from '@/i18n'

// The one place a message becomes a one-line label (inbox previews, reply
// banners, quote excerpts). Mirrors the backend's Message::previewLabel().
export function messagePreview(message) {
  if (!message) return ''
  if (message.redacted) return t('chat.messageDeleted')
  return (
    {
      image: `📷 ${t('chat.photo')}`,
      voice: `🎤 ${t('chat.voiceNote')}`,
      file: `📎 ${t('chat.file')}`,
    }[message.type] ?? (message.body || t('chat.newMessage'))
  )
}
