package com.plazapro.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.webkit.CookieManager;

import androidx.core.app.RemoteInput;

import org.json.JSONObject;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.UUID;

/**
 * Handles the notification's inline "Reply": posts the typed text to
 * POST /api/v1/conversations/{id}/messages without opening the app.
 *
 * Auth rides the WebView's own session: android.webkit.CookieManager is
 * process-wide and disk-persisted, so the Laravel session + XSRF cookies are
 * available even when the app has been killed. Sanctum's SPA mode needs three
 * things beyond the cookies — the URL-decoded XSRF-TOKEN echoed in
 * X-XSRF-TOKEN, and an Origin/Referer on the app's own host — all set below.
 * The route is idempotent-guarded, so the key is sent from the first attempt
 * (same convention as the web outbox).
 *
 * Outcome feeds back into the tray entry (which shows a spinner until the
 * same tag is re-notified): success appends the reply as "You"; failure
 * restores the entry and raises a "Reply not sent — open the chat" notice.
 */
public class ReplyReceiver extends BroadcastReceiver {
    static final String ACTION_REPLY = "com.plazapro.app.REPLY";
    static final String KEY_TEXT = "plaza_reply_text";

    private static final String FALLBACK_ORIGIN = "https://app.plaza-pro.com";
    private static volatile String cachedOrigin;

    @Override
    public void onReceive(Context context, Intent intent) {
        if (!ACTION_REPLY.equals(intent.getAction())) return;

        Bundle results = RemoteInput.getResultsFromIntent(intent);
        CharSequence typed = results == null ? null : results.getCharSequence(KEY_TEXT);
        String text = typed == null ? "" : typed.toString().trim();
        String conversationId = intent.getStringExtra(PlazaPush.EXTRA_CONVERSATION_ID);
        String tag = intent.getStringExtra(PlazaPush.EXTRA_TAG);
        String title = intent.getStringExtra(PlazaPush.EXTRA_TITLE);

        if (text.isEmpty() || conversationId == null || conversationId.isEmpty()
                || tag == null || tag.isEmpty()) {
            return;
        }

        // Network off the main thread; goAsync keeps the process alive for it.
        PendingResult pending = goAsync();
        Context app = context.getApplicationContext();
        String safeTitle = title == null || title.isEmpty() ? "PLAZA PRO" : title;

        new Thread(() -> {
            boolean sent;
            try {
                sent = postReply(app, conversationId, text);
            } catch (Exception e) {
                sent = false;
            }

            if (sent) {
                PlazaPush.showChatMessage(app, tag, conversationId, safeTitle, text, true);
            } else {
                PlazaPush.showReplyFailed(app, tag, conversationId, safeTitle);
            }
            pending.finish();
        }, "plaza-reply").start();
    }

    private boolean postReply(Context context, String conversationId, String text) throws Exception {
        String origin = origin(context);
        String cookies = CookieManager.getInstance().getCookie(origin);
        if (cookies == null || cookies.isEmpty()) return false;

        String xsrf = xsrfToken(cookies);
        if (xsrf == null) return false;

        URL url = new URL(origin + "/api/v1/conversations/" + conversationId + "/messages");
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        try {
            conn.setRequestMethod("POST");
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(8000);
            conn.setDoOutput(true);
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("Accept", "application/json");
            conn.setRequestProperty("Cookie", cookies);
            conn.setRequestProperty("X-XSRF-TOKEN", xsrf);
            conn.setRequestProperty("X-Idempotency-Key", UUID.randomUUID().toString());
            conn.setRequestProperty("Origin", origin);
            conn.setRequestProperty("Referer", origin + "/");

            byte[] body = new JSONObject().put("body", text)
                    .toString().getBytes(StandardCharsets.UTF_8);
            conn.getOutputStream().write(body);

            int status = conn.getResponseCode();
            return status >= 200 && status < 300;
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
    private static String origin(Context context) {
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
