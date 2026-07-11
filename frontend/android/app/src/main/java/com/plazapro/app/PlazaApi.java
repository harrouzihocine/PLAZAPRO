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
        return postForResult(context, path, body, idempotencyKey).status;
    }

    /** Status + response body — the duty service reads the server's `precision` cue. */
    static Result postForResult(Context context, String path, JSONObject body, String idempotencyKey)
            throws Exception {
        String origin = origin(context);
        String cookies = CookieManager.getInstance().getCookie(origin);
        if (cookies == null || cookies.isEmpty()) return new Result(-1, "");

        String xsrf = xsrfToken(cookies);
        if (xsrf == null) return new Result(-1, "");

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
                try (java.io.OutputStream out = conn.getOutputStream()) {
                    out.write(body.toString().getBytes(StandardCharsets.UTF_8));
                }
            }

            int status = conn.getResponseCode();
            String responseBody = "";
            if (status >= 200 && status < 300) {
                try (InputStream in = conn.getInputStream()) {
                    byte[] buffer = new byte[4096];
                    StringBuilder sb = new StringBuilder();
                    int read;
                    while ((read = in.read(buffer)) > 0) {
                        sb.append(new String(buffer, 0, read, StandardCharsets.UTF_8));
                    }
                    responseBody = sb.toString();
                } catch (Exception ignored) {
                    // A body-less 2xx is still a success.
                }
            }
            return new Result(status, responseBody);
        } finally {
            conn.disconnect();
        }
    }

    static final class Result {
        final int status;
        final String body;

        Result(int status, String body) {
            this.status = status;
            this.body = body;
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
            java.io.ByteArrayOutputStream raw = new java.io.ByteArrayOutputStream();
            byte[] chunk = new byte[4096];
            int read;
            while ((read = in.read(chunk)) != -1) {
                raw.write(chunk, 0, read);
            }
            JSONObject config = new JSONObject(raw.toString("UTF-8"));
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
