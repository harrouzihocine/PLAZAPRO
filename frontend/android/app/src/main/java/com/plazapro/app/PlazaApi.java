package com.plazapro.app;

import android.content.Context;
import android.webkit.CookieManager;

import org.json.JSONObject;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;

/**
 * Minimal cookie-auth client for the shell's out-of-app API calls (notification
 * actions: the chat quick-reply, the click-to-call "dialed" report).
 *
 * Auth rides the WebView's own session: android.webkit.CookieManager is
 * process-wide and disk-persisted, so the Laravel session + XSRF cookies are
 * available even when the app has been killed. Sanctum's SPA mode needs three
 * things beyond the cookies — the URL-decoded XSRF-TOKEN echoed in
 * X-XSRF-TOKEN, and an Origin/Referer on the app's own host — all set below.
 */
final class PlazaApi {
    private static final String FALLBACK_ORIGIN = "https://app.plaza-pro.com";
    private static volatile String cachedOrigin;

    private PlazaApi() {}

    /**
     * POST a JSON body (may be null for body-less endpoints) to an /api/v1
     * path with the WebView session. Returns true on a 2xx response.
     */
    static boolean post(Context context, String path, JSONObject body, String idempotencyKey)
            throws Exception {
        int status = postForStatus(context, path, body, idempotencyKey);
        return status >= 200 && status < 300;
    }

    /**
     * Same POST, but the raw HTTP status (-1 when no session cookies exist) —
     * for callers whose behaviour hangs on a specific code, like the duty
     * location service stopping on the 409 "off duty" refusal.
     */
    static int postForStatus(Context context, String path, JSONObject body, String idempotencyKey)
            throws Exception {
        String origin = origin(context);
        String cookies = CookieManager.getInstance().getCookie(origin);
        if (cookies == null || cookies.isEmpty()) return -1;

        String xsrf = xsrfToken(cookies);
        if (xsrf == null) return -1;

        URL url = new URL(origin + "/api/v1" + path);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("POST");
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(8000);
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("Accept", "application/json");
            conn.setRequestProperty("Cookie", cookies);
            conn.setRequestProperty("X-XSRF-TOKEN", xsrf);
            conn.setRequestProperty("Origin", origin);
            conn.setRequestProperty("Referer", origin + "/");
            if (idempotencyKey != null && !idempotencyKey.isEmpty()) {
                conn.setRequestProperty("X-Idempotency-Key", idempotencyKey);
            }

            if (body != null) {
                conn.setDoOutput(true);
                conn.getOutputStream().write(body.toString().getBytes(StandardCharsets.UTF_8));
            }

            return conn.getResponseCode();
        } finally {
            conn.disconnect();
        }
    }

    /** Laravel urlencodes cookie values; the header must carry the raw token. */
    private static String xsrfToken(String cookies) throws Exception {
        for (String pair : cookies.split(";")) {
            String trimmed = pair.trim();
            if (trimmed.startsWith("XSRF-TOKEN=")) {
                return URLDecoder.decode(
                        trimmed.substring("XSRF-TOKEN=".length()), "UTF-8");
            }
        }
        return null;
    }

    /**
     * The app's server origin, read once from the Capacitor config asset (the
     * same source the WebView loads from) so this never drifts from the shell.
     */
    static String origin(Context context) {
        String cached = cachedOrigin;
        if (cached != null) return cached;

        String origin = FALLBACK_ORIGIN;
        try (InputStream in = context.getAssets().open("capacitor.config.json")) {
            byte[] raw = new byte[in.available()];
            int read = in.read(raw);
            JSONObject config = new JSONObject(new String(raw, 0, Math.max(read, 0), StandardCharsets.UTF_8));
            String url = config.getJSONObject("server").getString("url");
            if (url.startsWith("http")) {
                origin = url.replaceAll("/+$", "");
            }
        } catch (Exception ignored) {
            // No/odd config asset — the production origin is the right default.
        }

        cachedOrigin = origin;
        return origin;
    }
}
