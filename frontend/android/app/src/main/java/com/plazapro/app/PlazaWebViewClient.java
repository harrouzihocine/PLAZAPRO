package com.plazapro.app;

import android.net.Uri;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;

/**
 * Cold-boot server failover for the remote-mode shell. The webview normally
 * loads https://app.plaza-pro.com (internet, through the Cloudflare tunnel).
 * With the office internet down and no service-worker cache yet (fresh
 * install, cleared storage), that load dies with a main-frame network error
 * and the user would be stuck on a grey error page — so walk the LAN doors:
 *
 *   app.plaza-pro.com → office.plaza-pro.com → https://192.168.1.200
 *
 * office.* needs DNS to resolve (public record → LAN IP); the bare IP needs
 * none — its cert is signed by the private CA in res/raw/plaza_lan_ca.pem
 * (scoped to that one IP by network_security_config.xml). Both hosts are in
 * capacitor.config.json server.allowNavigation, so they stay in the webview.
 *
 * This class only covers the no-page-at-all case. Once ANY page is running,
 * the web layer's own failover (frontend/src/utils/serverFailover.js — keep
 * the origin lists in sync) handles mid-session outages, because a service-
 * worker-served shell never raises a main-frame error here.
 */
class PlazaWebViewClient extends BridgeWebViewClient {
    static final String[] ORIGINS = {
        "https://app.plaza-pro.com",
        "https://office.plaza-pro.com",
        "https://192.168.1.200",
    };
    private static final long WINDOW_MS = 30_000;

    private long windowStartedAt = 0;
    private int hops = 0;

    PlazaWebViewClient(Bridge bridge) {
        super(bridge);
    }

    /**
     * TRUE cold-boot offline: Android's WebView never routes a MAIN-FRAME
     * navigation through the service worker, so with no network the app died
     * on its own error page before any web code ran. While the device reports
     * no internet, the shell answers app-origin requests from its native
     * mirror of the build (OfflineShellStore, synced in the background):
     * index.html for any SPA route, mirrored files for assets. Online, this
     * returns to Capacitor untouched — including the "internet up, server
     * down" case, which stays the origin walk's job (onReceivedError below).
     */
    @Override
    public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
        if ("GET".equalsIgnoreCase(request.getMethod())
                && originIndex(request.getUrl()) >= 0
                && OfflineShellStore.isDeviceOffline(view.getContext())) {
            String path = request.getUrl().getPath();
            if (path != null && servableOffline(path, request.isForMainFrame())) {
                WebResourceResponse local =
                        OfflineShellStore.serve(view.getContext(), path, request.isForMainFrame());
                if (local != null) return local;
            }
        }
        return super.shouldInterceptRequest(view, request);
    }

    /** SPA routes boot the index; only the mirrored static files otherwise. */
    private static boolean servableOffline(String path, boolean mainFrame) {
        if (mainFrame) {
            return !(path.startsWith("/api") || path.startsWith("/downloads")
                    || path.startsWith("/storage") || path.startsWith("/broadcasting"));
        }
        return path.startsWith("/assets/") || path.startsWith("/icons/")
                || path.equals("/manifest.webmanifest");
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        super.onReceivedError(view, request, error);
        if (!request.isForMainFrame()) return;

        int failed = originIndex(request.getUrl());
        if (failed < 0) return; // an external page (OSM tile page etc.) — not ours to retry

        // One walk of the list per cold load, not an endless loop: after every
        // origin failed inside the window, stay on the error page (a later
        // retry — user reopens the app — starts a fresh window).
        long now = System.currentTimeMillis();
        if (now - windowStartedAt > WINDOW_MS) {
            windowStartedAt = now;
            hops = 0;
        }
        if (hops >= ORIGINS.length - 1) return;
        hops++;

        String next = ORIGINS[(failed + 1) % ORIGINS.length];
        view.post(() -> view.loadUrl(next + "/"));
    }

    private static int originIndex(Uri url) {
        if (url == null || url.getHost() == null) return -1;
        String origin = url.getScheme() + "://" + url.getHost();
        for (int i = 0; i < ORIGINS.length; i++) {
            if (ORIGINS[i].equals(origin)) return i;
        }
        return -1;
    }
}
