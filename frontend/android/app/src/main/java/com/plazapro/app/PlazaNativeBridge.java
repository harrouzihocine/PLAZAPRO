package com.plazapro.app;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageInfo;
import android.net.Uri;
import android.webkit.JavascriptInterface;

import androidx.core.content.pm.PackageInfoCompat;

import org.json.JSONObject;

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
 *   getAppVersion()     → {"versionName":"1.2.0","versionCode":4} of this build
 *   openExternal(url)   → hand an http(s) URL to the system browser (APK update)
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

    /**
     * This build's version, for the web layer's "update available" banner
     * (utils/appUpdate.js compares it against /downloads/version.json). Shells
     * without this method are treated as the last release that lacked it.
     */
    /**
     * Open a URL in the system browser (Chrome), outside the webview. The APK
     * update download needs this: the webview has no DownloadListener, and the
     * old http:// scheme-mismatch trick stopped ejecting on the LAN fallback
     * origins once they joined server.allowNavigation (Capacitor's host match
     * ignores the scheme). Returns false so old shells without the method make
     * the web layer fall back to that legacy trick.
     */
    @JavascriptInterface
    public boolean openExternal(String url) {
        if (url == null || !(url.startsWith("https://") || url.startsWith("http://"))) {
            return false;
        }
        try {
            Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            context.startActivity(intent);
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    @JavascriptInterface
    public String getAppVersion() {
        try {
            PackageInfo info = context.getPackageManager()
                    .getPackageInfo(context.getPackageName(), 0);
            JSONObject json = new JSONObject();
            json.put("versionName", info.versionName == null ? "" : info.versionName);
            json.put("versionCode", PackageInfoCompat.getLongVersionCode(info));
            return json.toString();
        } catch (Exception e) {
            return "";
        }
    }
}
