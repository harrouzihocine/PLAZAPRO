package com.plazapro.app;

import android.net.Uri;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;

import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * Cold-boot server failover for the remote-mode shell. The webview normally
 * loads https://app.plaza-pro.com (internet, through the Cloudflare tunnel).
 * When that door is dead — office internet down, tunnel down, DNS black-holed
 * — the user would be stuck on a blank/error page before any web code ran,
 * so the shell walks the LAN doors:
 *
 *   app.plaza-pro.com → office.plaza-pro.com → https://192.168.1.200
 *
 * office.* needs DNS to resolve (public record → LAN IP); the bare IP needs
 * none — its cert is signed by the private CA in res/raw/plaza_lan_ca.pem
 * (scoped to that one IP by network_security_config.xml, which also covers
 * the HttpsURLConnection probes below). Both hosts are in
 * capacitor.config.json server.allowNavigation, so they stay in the webview.
 *
 * The walk is PROBE-DRIVEN, not hop-and-hope: on a main-frame failure (network
 * error, 5xx, or a load stalled with no progress) all three doors' /up are
 * probed in parallel and the webview navigates to the first that answers, by
 * priority. Probing matters twice over: the bare-IP probe needs no DNS, so it
 * answers in milliseconds even while name resolution hangs for the other two
 * (a dead upstream resolver can sit on a lookup for 30 s+); and a probe reads
 * the status, so a Cloudflare 502/521 ("edge up, app down") does not count as
 * a door. Only when NOTHING answers does the branded error page load — and
 * that page (native-shell/index.html) walks the same list itself; it must
 * never blind-retry app.* alone, because navigator.onLine is true on office
 * Wi-Fi even with the internet down (the "stuck on the blue screen" bug).
 *
 * COLD BOOT is the load we do NOT get to watch from the start: Capacitor's
 * Bridge fires webView.loadUrl(server.url) from inside super.onCreate, on its
 * OWN default client, before MainActivity swaps this one in — so our
 * onPageStarted never fires for that first navigation, and if app.* hangs the
 * only callback we would ever get is onReceivedError, minutes later, after the
 * OS TCP timeout (that IS the "logo then a long blue screen" report). So
 * MainActivity calls onShellCreated() right after installing us: we seed the
 * boot origin and probe the doors immediately, jumping to a live LAN door in
 * seconds instead of waiting the connection out on a blank splash.
 *
 * Once ANY page is running, the web layer's own failover
 * (frontend/src/utils/serverFailover.js) also handles mid-session outages.
 * Keep the origin list in sync in all THREE places: here, serverFailover.js,
 * and native-shell/index.html.
 */
class PlazaWebViewClient extends BridgeWebViewClient {
    static final String[] ORIGINS = {
        "https://app.plaza-pro.com",
        "https://office.plaza-pro.com",
        "https://192.168.1.200",
    };

    private static final int PROBE_TIMEOUT_MS = 3000;
    /** Total time one walk may spend waiting on probes (they run in parallel). */
    private static final long WALK_BUDGET_MS = 6000;
    /** A running main-frame load with no progress for this long is presumed hung. */
    private static final long STALL_AFTER_MS = 12_000;
    /**
     * The cold-boot load is already in flight and un-watched, so it gets a
     * tighter leash: if nothing has painted within this window we probe. A
     * healthy app.* answers in well under a second on any live connection.
     */
    private static final long BOOT_STALL_MS = 6000;

    /**
     * Unbounded on purpose: a probe whose DNS lookup hangs holds its thread
     * past the walk budget (lookups ignore connect timeouts), and a later
     * walk must not queue behind it. Idle threads are reaped after 60 s.
     */
    private static final ExecutorService probePool = Executors.newCachedThreadPool();

    private final Bridge bridge;
    private final AtomicBoolean walking = new AtomicBoolean(false);

    /**
     * Origin of the in-flight main-frame navigation (main thread only). Seeded
     * to the boot origin because Capacitor started that load before we existed
     * — without this, its eventual error looks like a stale nav and gets
     * swallowed, and the app never recovers.
     */
    private String currentNavOrigin = ORIGINS[0];
    /** True once a real page has painted; mid-session the web layer takes over. */
    private boolean settled = false;
    private Runnable stallWatchdog;

    PlazaWebViewClient(Bridge bridge) {
        super(bridge);
        this.bridge = bridge;
    }

    /**
     * Called by MainActivity immediately after this client is installed — i.e.
     * right after Capacitor already kicked off the cold-boot load to app.* on
     * its default client. Probe the doors now: if app.* is unreachable (office
     * internet down — the whole reason the LAN doors exist), jump straight to a
     * working one rather than staring at the splash until the OS gives up on
     * the connection. If app.* answers, the probe changes nothing and the
     * in-flight load is left to finish; a stall watchdog backs it up.
     */
    void onShellCreated(WebView view) {
        startWalk(view, ORIGINS[0], true);
        armStallWatchdog(view, BOOT_STALL_MS);
    }

    /**
     * TRUE cold-boot offline: Android's WebView never routes a MAIN-FRAME
     * navigation through the service worker, so with no network the app died
     * on its own error page before any web code ran. While the device reports
     * no internet, the shell answers app-origin requests from its native
     * mirror of the build (OfflineShellStore, synced in the background):
     * index.html for any SPA route, mirrored files for assets. Online, this
     * returns to Capacitor untouched — including the "internet up, server
     * down" case, which stays the origin walk's job.
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
    public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
        super.onPageStarted(view, url, favicon);
        int idx = originIndex(url == null ? null : Uri.parse(url));
        currentNavOrigin = idx >= 0 ? ORIGINS[idx] : null;
        settled = false;
        if (currentNavOrigin != null) {
            armStallWatchdog(view, STALL_AFTER_MS);
        } else {
            cancelStallWatchdog(view);
        }
    }

    @Override
    public void onPageFinished(WebView view, String url) {
        super.onPageFinished(view, url);
        // A real origin page painted: the web layer owns outages from here.
        if (originIndex(url == null ? null : Uri.parse(url)) >= 0) settled = true;
        cancelStallWatchdog(view);
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        String failed = failedOrigin(request);
        if (failed == null) {
            super.onReceivedError(view, request, error); // not ours — Capacitor's business
            return;
        }
        if (!failed.equals(currentNavOrigin)) {
            // A navigation the walk itself superseded (ERR_ABORTED for the old
            // URL). Acting on it — or letting Capacitor load the error page —
            // would cancel the replacement load. Swallow it.
            return;
        }
        cancelStallWatchdog(view);
        startWalk(view, failed, false);
    }

    @Override
    public void onReceivedHttpError(WebView view, WebResourceRequest request,
            WebResourceResponse errorResponse) {
        String failed = failedOrigin(request);
        if (failed != null && failed.equals(currentNavOrigin)
                && errorResponse.getStatusCode() >= 500) {
            // Tunnel edge up, app down (Cloudflare 502/521 page): walk the LAN
            // doors instead of rendering the edge's error page.
            cancelStallWatchdog(view);
            startWalk(view, failed, false);
            return;
        }
        super.onReceivedHttpError(view, request, errorResponse);
    }

    /** The failed main-frame origin when it is one of ours, else null. */
    private String failedOrigin(WebResourceRequest request) {
        if (!request.isForMainFrame()) return null;
        int idx = originIndex(request.getUrl());
        return idx >= 0 ? ORIGINS[idx] : null;
    }

    /**
     * Probe every door in parallel, then (on the UI thread) navigate to the
     * first that answered, by priority. `stalled` marks a walk over a load that
     * has not yet failed (the boot probe, or the stall watchdog): that load is
     * only uprooted for a door that provably answers while its own origin does
     * not — a slow-but-alive connection, and the preferred app.* door, are
     * always left to finish.
     */
    private void startWalk(WebView view, String fromOrigin, boolean stalled) {
        if (!walking.compareAndSet(false, true)) return;

        List<Future<Boolean>> probes = new ArrayList<>(ORIGINS.length);
        for (String origin : ORIGINS) {
            probes.add(probePool.submit(() -> reachable(origin)));
        }
        probePool.execute(() -> {
            String target = null;
            long deadline = System.currentTimeMillis() + WALK_BUDGET_MS;
            for (int i = 0; i < ORIGINS.length; i++) {
                try {
                    long wait = Math.max(1, deadline - System.currentTimeMillis());
                    if (probes.get(i).get(wait, TimeUnit.MILLISECONDS)) {
                        target = ORIGINS[i];
                        break;
                    }
                } catch (Exception stillUnreachable) {
                    // timeout or failure — try the next door
                }
            }
            String dest = target;
            view.post(() -> {
                walking.set(false);
                if (stalled && (dest == null || dest.equals(fromOrigin))) {
                    // Nothing better than what is already loading — leave the
                    // in-flight load alone and keep watching it.
                    armStallWatchdog(view, STALL_AFTER_MS);
                } else if (dest != null) {
                    // Claim the new origin now, before onPageStarted fires, so
                    // the aborted old load's error is recognised as stale.
                    currentNavOrigin = dest;
                    view.loadUrl(dest + "/");
                } else {
                    // No door answered: branded "reconnecting" page (it keeps
                    // walking the doors itself until one opens).
                    view.loadUrl(bridge.getErrorUrl());
                }
            });
        });
    }

    private void armStallWatchdog(WebView view, long delayMs) {
        cancelStallWatchdog(view);
        String origin = currentNavOrigin;
        if (origin == null || settled) return;
        stallWatchdog = () -> {
            stallWatchdog = null;
            if (settled) return;
            if (view.getProgress() >= 80) {
                armStallWatchdog(view, STALL_AFTER_MS); // nearly there — never interrupt it
                return;
            }
            startWalk(view, origin, true);
        };
        view.postDelayed(stallWatchdog, delayMs);
    }

    private void cancelStallWatchdog(WebView view) {
        if (stallWatchdog != null) {
            view.removeCallbacks(stallWatchdog);
            stallWatchdog = null;
        }
    }

    /**
     * One door's health check: GET /up (Laravel's health endpoint, public on
     * all three origins). Any status below 500 proves the app answered; a 5xx
     * is an edge or proxy speaking for a dead app. The bare-IP probe's TLS
     * trust comes from network_security_config.xml (it applies to
     * HttpsURLConnection exactly as to the webview).
     */
    private static boolean reachable(String origin) {
        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(origin + "/up").openConnection();
            conn.setConnectTimeout(PROBE_TIMEOUT_MS);
            conn.setReadTimeout(PROBE_TIMEOUT_MS);
            conn.setInstanceFollowRedirects(false);
            int status = conn.getResponseCode();
            return status > 0 && status < 500;
        } catch (Exception e) {
            return false;
        } finally {
            if (conn != null) conn.disconnect();
        }
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
