import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useApi } from '@/composables/useApi'

// Laravel Echo over Reverb (websockets). One lazily-created instance for the app.
// Private-channel auth reuses the shared Axios instance so it carries the Sanctum
// session cookie + XSRF header (baseURL is overridden to hit /broadcasting/auth,
// which sits outside the /api/v1 prefix). If Reverb is unreachable the app still
// works — the bell/thread fall back to their HTTP fetches.

// pusher-js is the transport Reverb speaks; Echo expects it on window.
window.Pusher = Pusher

let echo = null

export function getEcho() {
  if (echo) return echo

  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) return null // real-time not configured; caller degrades gracefully

  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        useApi()
          .post(
            '/broadcasting/auth',
            { socket_id: socketId, channel_name: channel.name },
            { baseURL: '/' },
          )
          .then((res) => callback(null, res.data))
          .catch((err) => callback(err))
      },
    }),
  })

  return echo
}

export function disconnectEcho() {
  if (echo) {
    echo.disconnect()
    echo = null
  }
}
