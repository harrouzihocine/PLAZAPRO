# Android App (Capacitor shell)

The dedicated Android app is a **Capacitor 7 webview shell in REMOTE mode**: it
loads `https://app.plaza-pro.com` directly (`frontend/capacitor.config.json`
`server.url`). Same features, same API, same login (Sanctum cookies live on the
real origin, so nothing backend-side changed). iOS stays on the PWA.

Consequences of remote mode:

- **Web deploys ARE app updates.** Every `deploy.sh` run updates what the app
  shows. The APK only needs rebuilding when the *native shell* changes:
  Capacitor upgrades, plugins, icons/splash, Android permissions, orientation.
- Split-horizon DNS just works: the webview resolves `app.plaza-pro.com` like
  any browser on the phone (LAN → local nginx :443, outside → Cloudflare tunnel).
- The site's strict CSP (`script-src 'self'`) can block Capacitor's inline
  bridge injection, so `window.Capacitor` may be absent inside the app. That is
  fine — no feature relies on the bridge. In-app detection uses the UA marker
  `PlazaProNative/1` appended by the shell (`frontend/src/utils/nativeApp.js`);
  the service worker is skipped/unregistered inside the shell (`main.js`).

Layout:

| Path | What |
|---|---|
| `frontend/capacitor.config.json` | app id `com.plazapro.app`, remote `server.url`, UA marker, splash config |
| `frontend/android/` | native Android project (committed; build outputs gitignored) |
| `frontend/native-shell/` | stub `webDir` (remote mode never shows it) |
| `frontend/assets/` | icon/splash source images for `@capacitor/assets` |
| `docker/android/Dockerfile` | pinned JDK 21 + SDK 35 build image (`plaza-android-sdk:35-jdk21`) |
| `scripts/build-android.sh` | one-command signed build |
| `scripts/gen-android-assets.mjs` | regenerate `frontend/assets/` from the brand logo |

## Building the APK

This host has no Android SDK and no sudo — everything runs in containers:

```sh
./scripts/build-android.sh
```

That runs `npm ci` + `cap sync android` (node:20-alpine), builds the pinned SDK
image if missing, runs `gradlew assembleRelease` with the keystore mounted
read-only, verifies the signature with `apksigner`, and drops:

```
frontend/android/artifacts/plaza-pro.apk          stable name (published)
frontend/android/artifacts/plaza-pro-v1.0.0.apk   versioned copy (archive)
frontend/android/artifacts/version.json           read by the /install page
```

Gradle caches persist in `~/.cache/plaza-android-gradle`; the first build
downloads Gradle + dependencies (minutes), later ones are fast.

## Versioning — bump BEFORE every distributed rebuild

In `frontend/android/app/build.gradle`:

- `versionCode` — integer, **must strictly increase**, or Android refuses to
  install the new APK over the old one. Bump by 1 every distributed build.
- `versionName` — human-readable (`1.0.0`); shown on the /install page via
  `version.json`. Bump the patch/minor to match the change.

This is the *shell* version, not the web app's — remote mode means the web app
inside is always current.

## Keystore — generate once, never lose it

Lives with the prod secrets, **outside git** (root `.gitignore` blocks
`*.keystore` and `keystore.properties`):

```
~/plaza-prod/secrets/android/plaza-pro-release.keystore
~/plaza-prod/secrets/android/keystore.properties    (storeFile/storePassword/keyAlias/keyPassword)
```

Android only accepts updates signed by the same key. **Losing the keystore
means every phone must uninstall and reinstall by hand** — back both files up
off this server (they are small; keep them with whatever holds the other prod
secrets). `app/build.gradle` picks the config up from `PLAZA_KEYSTORE_PROPS`
(set by build-android.sh) or a local `android/keystore.properties`.

It was generated once with (do NOT rerun over an existing keystore):

```sh
docker run --rm -u "$(id -u):$(id -g)" -e KSPASS -v ~/plaza-prod/secrets/android:/ks -w /ks \
  plaza-android-sdk:35-jdk21 keytool -genkeypair -keystore plaza-pro-release.keystore \
  -alias plaza-pro -keyalg RSA -keysize 4096 -validity 10950 \
  -storepass:env KSPASS -keypass:env KSPASS -dname "CN=PLAZA PRO, O=PLAZA PRO, C=DZ"
```

## Publishing a new APK

The APK is served by prod nginx at **`https://app.plaza-pro.com/downloads/plaza-pro.apk`**
from `~/plaza-prod/downloads/` (gitignored, mounted read-only into nginx — a
dedicated dir because `frontend/dist/` is wiped by every SPA build and a binary
does not belong in git):

```sh
./scripts/build-android.sh
cp frontend/android/artifacts/plaza-pro.apk frontend/android/artifacts/version.json ~/plaza-prod/downloads/
```

No restart needed — nginx serves the new file immediately. `version.json` is
what makes the /install page show the new version number. Phones update by
downloading and installing over the top (same signature, higher versionCode).

Publishing `version.json` is also what lights the **in-app update banner**:
the shell compares its own build (`PlazaNative.getAppVersion()`, treated as
code 3 for the pre-1.2.0 fleet that lacks the method) against
`/downloads/version.json` on every launch/foreground (hourly at most,
`utils/appUpdate.js`) and shows a gold "A new version is out → Update" bar
that deep-links to /install. Dismissing it silences that release only.

## Installing on a phone (what staff do)

Open **Menu → Mobile App** (or `/install`) → *Download for Android (.apk)*.
Chrome warns once about unknown apps: **Settings → "Allow from this source"**,
back, **Install**. The page shows these steps.

## Push notifications (FCM) — one-time setup

The shell shows **system-tray notifications** (chat messages, visits, payments,
reminders — everything that hits the bell) with deep links back into the app,
even when it is closed. It rides Firebase Cloud Messaging; without the two
files below everything still builds and runs, push just stays off.

One-time setup (~10 minutes, any Google account):

1. <https://console.firebase.google.com> → **Add project** (name e.g.
   `plaza-pro`, Analytics off).
2. **Add app → Android**, package name exactly `com.plazapro.app` → download
   **google-services.json** → put it at
   `~/plaza-prod/secrets/android/google-services.json` (build-android.sh copies
   it in; it is gitignored).
3. **Project settings → Service accounts → Generate new private key** →
   save the JSON on the prod host, e.g.
   `~/plaza-prod/secrets/firebase-credentials.json`, mount it into the app +
   queue-worker containers, and set in the backend `.env`:
   `FIREBASE_CREDENTIALS=/var/www/html/storage/app/firebase-credentials.json`
   (match the mount path). Restart the queue worker.
4. Rebuild + republish the APK (`scripts/build-android.sh` — bump versionCode
   first). Phones get the runtime "allow notifications" prompt (Android 13+)
   on first launch.

How it works: `DomainNotification` adds an FCM channel when
`FIREBASE_CREDENTIALS` is set, the recipient has a registered device
(`device_tokens`, registered by the web layer via `window.PlazaNative`), and
they haven't muted the kind's category (`users.push_prefs`, edited in-app
under account menu → Notifications — tray push only, the bell always
delivers; security kinds are never mutable). The backend sends **data-only**
messages (`FcmClient`, no SDK); the shell's `PlazaMessagingService` renders
them — channel per kind, one tray entry per conversation (payload `tag`), tap
→ `MainActivity` → the SPA routes to the payload `link`. Foreground pushes are
suppressed (the in-app toast/chime already covers them). Logout releases the
device token.

Chat entries are **MessagingStyle conversations** (v1.2.0+): consecutive
messages stack in one tray entry, and an inline **Reply** action (Android 7+)
sends without opening the app — `ReplyReceiver` posts to
`/api/v1/conversations/{id}/messages` (id from the payload `subject_id`)
reusing the WebView's session cookies + XSRF token, then appends the sent text
as "You" (or raises a "Reply not sent — tap to open the chat" notice if the
session is gone or the network drops).

## Office-LAN failover (works with the internet down)

One backend, three doors, walked in priority order (v1.5.0+):

| origin | path | needs |
| --- | --- | --- |
| `https://app.plaza-pro.com` | Cloudflare tunnel | internet |
| `https://office.plaza-pro.com` | LAN direct, Let's Encrypt cert | DNS (public record → LAN IP) |
| `https://192.168.1.200` | LAN direct, private-CA cert | nothing — the no-internet door |

Two cooperating halves, lists kept in sync:

- **`PlazaWebViewClient.java`** (shell): a main-frame load error on one of the
  origins loads the next — covers the cold boot with no service-worker cache,
  where no JS can run. Both LAN hosts are in `capacitor.config.json`
  `server.allowNavigation` so they stay inside the webview.
- **`frontend/src/utils/serverFailover.js`** (web layer, ships with deploys):
  when the network store flags offline, it probes the current origin's `/up`,
  then the others (opaque no-cors fetches), and hard-navigates to the first
  that answers. Same watcher walks a phone that left the building back to
  `app.*` over mobile data. A slim banner (OfflineBanner.vue) shows while
  parked on a LAN origin; cold starts always begin at `app.*`.

The bare-IP door works because public CAs can't issue for a private IP: the
server presents a leaf signed by our own **LAN CA** (issue/renew:
`scripts/setup-lan-ip-cert.sh` in the prod checkout; nginx serves it to no-SNI
clients from the `default_server` block). The CA's public cert is committed at
`res/raw/plaza_lan_ca.pem` and pinned to `192.168.1.200` only via
`res/xml/network_security_config.xml` — browsers still warn on the bare IP,
and every other host keeps the system trust store. The CA key lives in
`~/plaza-prod/secrets/lan-ip-ca/` (guard like the keystore; regenerating the
CA = rebuild + redistribute the APK).

Sessions are per-origin cookies, so a hop can land on the login screen.
`SESSION_DOMAIN=.plaza-pro.com` on prod lets `app.` ↔ `office.` share the
session; the bare-IP origin always logs in fresh. Push/tray-reply keeps
targeting `app.*` (it needs internet anyway — FCM is down when the tunnel is).

## Future: bundled-assets mode (Play Store)

If the app ever goes to a store, remote mode won't pass review. Switch:
`webDir` → `"dist"`, drop `server.url`, build the SPA first, and make API/Echo
base URLs absolute (`https://app.plaza-pro.com`) — cookies then become
cross-origin (Sanctum stateful domains + CORS), which is exactly the complexity
remote mode avoids today.

## Phone test checklist (after install/update)

1. APK downloads from /install and installs (warning → allow → install).
2. App opens portrait, navy splash, gold icon on launcher.
3. Login works and survives app restarts (cookie session).
4. Chat: bell + message arrives live (websocket wss through the same origin).
5. Media upload from camera and from gallery (unit/client media, chat voice note).
6. Logout → login as another user.
7. On office Wi-Fi AND on mobile data (split-horizon: both must work).
8. LAN failover: on office Wi-Fi with the WAN cable pulled, the app lands on
   an office origin (blue "office server" banner) and works; plugging the
   internet back in and cold-starting the app returns it to app.plaza-pro.com.
