package com.plazapro.app;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.webkit.WebView;

import androidx.activity.OnBackPressedCallback;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.getcapacitor.BridgeActivity;
import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;

/**
 * The Capacitor shell activity, extended for system-tray push:
 *  - binds `window.PlazaNative` (addJavascriptInterface — CSP-proof, unlike the
 *    remote-mode Capacitor bridge; see PlazaNativeBridge);
 *  - creates the notification channels and asks for POST_NOTIFICATIONS (13+);
 *  - fetches/refreshes the FCM token for the web layer to register;
 *  - routes notification taps: the tapped link is stashed, the running page is
 *    poked with a `plaza:push-open` event, a cold page consumes it on boot.
 */
public class MainActivity extends BridgeActivity {
    private static volatile boolean inForeground = false;

    static boolean isInForeground() {
        return inForeground;
    }

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        boolean pushSupported = !FirebaseApp.getApps(this).isEmpty();

        WebView webView = getBridge().getWebView();
        webView.addJavascriptInterface(new PlazaNativeBridge(this, pushSupported), "PlazaNative");

        // Cold-boot LAN failover: replace Capacitor's client with our subclass
        // (same behavior + main-frame-error fallback to the office origins).
        webView.setWebViewClient(new PlazaWebViewClient(getBridge()));

        PlazaPush.createChannels(this);
        requestNotificationPermission();
        fetchPushToken(pushSupported);
        stashLink(getIntent());

        // Hardware back, Facebook-style (Capacitor 7 ships no handling at all,
        // so without this the first press killed the activity from anywhere).
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                dispatchBackToWeb();
            }
        });
    }

    /**
     * Ask the page first (utils/appBack.js): it closes the top overlay, steps
     * the router back toward the dashboard, or arms the double-press exit.
     * 'exit' — and any page that cannot answer and has no history — backgrounds
     * the app (moveTaskToBack) instead of finishing it, so reopening is warm.
     */
    private void dispatchBackToWeb() {
        WebView webView = getBridge().getWebView();
        if (webView == null) {
            moveTaskToBack(true);
            return;
        }
        webView.evaluateJavascript(
                "typeof window.__plazaHandleBack === 'function' ? window.__plazaHandleBack() : 'unhandled'",
                result -> {
                    if ("\"exit\"".equals(result)) {
                        moveTaskToBack(true);
                    } else if (!"\"handled\"".equals(result)) {
                        // Boot splash or a stranded page: plain history, then out.
                        if (webView.canGoBack()) webView.goBack();
                        else moveTaskToBack(true);
                    }
                });
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        // Warm tap (app already running, singleTask): stash the link and poke
        // the page — its listener consumes the pending link and navigates.
        if (stashLink(intent)) {
            notifyWeb("plaza:push-open");
        }
    }

    @Override
    public void onResume() {
        super.onResume();
        inForeground = true;
    }

    @Override
    public void onPause() {
        super.onPause();
        inForeground = false;
    }

    /** @return true when the intent carried a notification deep link. */
    private boolean stashLink(Intent intent) {
        String link = intent == null ? null : intent.getStringExtra(PlazaPush.EXTRA_LINK);
        if (link == null || link.isEmpty()) return false;
        PlazaNativeBridge.stashPendingLink(link);
        return true;
    }

    private void requestNotificationPermission() {
        if (Build.VERSION.SDK_INT < 33) return;
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                == PackageManager.PERMISSION_GRANTED) {
            return;
        }
        ActivityCompat.requestPermissions(
                this, new String[] {Manifest.permission.POST_NOTIFICATIONS}, 1001);
    }

    /**
     * Ask Firebase for the current registration token (issued on first launch,
     * rotated rarely). Stored for `PlazaNative.getPushToken()`; a live page is
     * poked so it re-registers without waiting for the next boot. Builds
     * without google-services.json have no FirebaseApp — push simply stays off.
     */
    private void fetchPushToken(boolean pushSupported) {
        if (!pushSupported) return;
        FirebaseMessaging.getInstance().getToken().addOnCompleteListener(task -> {
            if (!task.isSuccessful() || task.getResult() == null) return;
            PlazaNativeBridge.storeToken(this, task.getResult());
            notifyWeb("plaza:push-token");
        });
    }

    /** Dispatch a bare DOM event into the page (evaluateJavascript is CSP-proof). */
    private void notifyWeb(String eventName) {
        WebView webView = getBridge().getWebView();
        if (webView == null) return;
        webView.post(() -> webView.evaluateJavascript(
                "window.dispatchEvent(new Event('" + eventName + "'))", null));
    }
}
