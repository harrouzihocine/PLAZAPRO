package com.plazapro.app;

import android.content.Context;
import android.content.SharedPreferences;
import android.webkit.JavascriptInterface;

/**
 * The shell↔web contract, exposed to the page as `window.PlazaNative` via
 * addJavascriptInterface. This is deliberate: the site's strict CSP
 * (script-src 'self') blocks Capacitor's remote-mode bridge injection, but a
 * Java object bound into the JS context needs no <script> at all — it survives
 * the CSP untouched (the web side feature-detects it, see utils/nativePush.js).
 *
 * Surface (all main-thread-safe, called from the page):
 *   getPushToken()      → the device's FCM token ("" until Firebase issues one)
 *   consumePendingLink()→ the deep link of the tapped notification, once
 *   isPushSupported()   → whether this build carries Firebase config
 */
public class PlazaNativeBridge {
    private static final String PREFS = "plaza_native";
    private static final String KEY_TOKEN = "fcm_token";

    /** Deep link stashed by a notification tap until the web app claims it. */
    private static volatile String pendingLink = null;

    private final Context context;
    private final boolean pushSupported;

    PlazaNativeBridge(Context context, boolean pushSupported) {
        this.context = context.getApplicationContext();
        this.pushSupported = pushSupported;
    }

    static void storeToken(Context context, String token) {
        prefs(context).edit().putString(KEY_TOKEN, token == null ? "" : token).apply();
    }

    static void stashPendingLink(String link) {
        if (link != null && !link.isEmpty()) pendingLink = link;
    }

    private static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    @JavascriptInterface
    public String getPushToken() {
        return prefs(context).getString(KEY_TOKEN, "");
    }

    @JavascriptInterface
    public String consumePendingLink() {
        String link = pendingLink;
        pendingLink = null;
        return link == null ? "" : link;
    }

    @JavascriptInterface
    public boolean isPushSupported() {
        return pushSupported;
    }
}
