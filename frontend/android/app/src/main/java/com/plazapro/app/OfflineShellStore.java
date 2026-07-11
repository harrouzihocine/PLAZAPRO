package com.plazapro.app;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.webkit.WebResourceResponse;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Native on-disk mirror of the web build, for TRUE cold-boot offline.
 *
 * The service worker precaches the whole build, but Android's WebView never
 * routes the MAIN-FRAME navigation through it: a cold start with no network
 * dies with a main-frame error before any web code runs (the SW covers pages
 * that are already open, not the boot). So the shell keeps its own copy:
 *
 *   sync()   on app start/resume (throttled): fetch /precache.json from the
 *            primary origin, and when the build hash is new, download every
 *            file into files/offline_shell/<build>/. The ".complete" marker
 *            is written LAST — a partial download is never served, and the
 *            next sync retries it (atomic, same rule as the SW's install).
 *
 *   serve()  from PlazaWebViewClient.shouldInterceptRequest, ONLY while the
 *            device reports no internet-capable network: main-frame requests
 *            get index.html (any SPA route), subresources their mirrored
 *            file. Online — or for anything not mirrored — the shell stays
 *            completely out of the way (null → normal network path, so the
 *            LAN origin walk still handles "internet up, server down").
 *
 * Everything mirrored is public static content (the SPA shell + hashed
 * assets) — no cookies, no API data; CRM data offline stays the job of the
 * web layer's IndexedDB snapshots.
 */
final class OfflineShellStore {
    private static final String DIR = "offline_shell";
    private static final String COMPLETE_MARKER = ".complete";
    private static final long SYNC_EVERY_MS = 10 * 60_000;
    private static final int FETCH_TIMEOUT_MS = 15_000;

    private static final ExecutorService worker = Executors.newSingleThreadExecutor();
    private static volatile long lastSyncAt = 0;

    private OfflineShellStore() {}

    /** No network at all, or one without internet capability (airplane mode). */
    static boolean isDeviceOffline(Context context) {
        try {
            ConnectivityManager cm =
                    (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return false;
            Network network = cm.getActiveNetwork();
            if (network == null) return true;
            NetworkCapabilities caps = cm.getNetworkCapabilities(network);
            // An unvalidated LAN still counts as "online" here — the origin
            // walk must keep its chance to reach the office doors.
            return caps == null || !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        } catch (Exception e) {
            return false;
        }
    }

    /** Refresh the mirror in the background (no-op when the build is current). */
    static void sync(Context context) {
        long now = System.currentTimeMillis();
        if (now - lastSyncAt < SYNC_EVERY_MS) return;
        lastSyncAt = now;
        Context app = context.getApplicationContext();
        worker.execute(() -> doSync(app));
    }

    private static void doSync(Context context) {
        try {
            String origin = PlazaApi.origin(context);
            // Cache-buster: the manifest must never come from an HTTP/CDN cache.
            byte[] raw = fetch(origin + "/precache.json?t=" + System.currentTimeMillis());
            if (raw == null) return;

            JSONObject meta = new JSONObject(new String(raw, StandardCharsets.UTF_8));
            String build = meta.getString("build");
            if (build.isEmpty() || !build.matches("[a-f0-9]+")) return;

            File root = new File(context.getFilesDir(), DIR);
            File dir = new File(root, build);
            if (new File(dir, COMPLETE_MARKER).exists()) return; // current

            JSONArray urls = meta.getJSONArray("urls");
            for (int i = 0; i < urls.length(); i++) {
                String path = urls.getString(i);
                if (!path.startsWith("/") || path.contains("..")) continue;
                byte[] body = fetch(origin + path);
                if (body == null) return; // incomplete — no marker, next sync retries
                write(new File(dir, localName(path)), body);
            }
            write(new File(dir, COMPLETE_MARKER), new byte[0]);
            dropOtherBuilds(root, build);
        } catch (Exception ignored) {
            // Best-effort: the previous complete mirror (if any) keeps serving.
        }
    }

    /**
     * The mirrored answer for an offline request, or null (caller falls back
     * to the normal network path). Any main-frame path boots index.html — the
     * SPA router owns the URL from there, exactly like nginx's fallback.
     */
    static WebResourceResponse serve(Context context, String path, boolean mainFrame) {
        try {
            File dir = completeDir(context);
            if (dir == null) return null;

            File file = new File(dir, mainFrame ? "index.html" : localName(path));
            if (!file.getCanonicalPath().startsWith(dir.getCanonicalPath()) || !file.isFile()) {
                return null;
            }
            String name = file.getName();
            return new WebResourceResponse(mime(name), charset(name), new FileInputStream(file));
        } catch (Exception e) {
            return null;
        }
    }

    private static File completeDir(Context context) {
        File[] builds = new File(context.getFilesDir(), DIR).listFiles();
        if (builds == null) return null;
        for (File dir : builds) {
            if (new File(dir, COMPLETE_MARKER).exists()) return dir;
        }
        return null;
    }

    private static String localName(String path) {
        return "/".equals(path) ? "index.html" : path.substring(1);
    }

    private static void dropOtherBuilds(File root, String keep) {
        File[] builds = root.listFiles();
        if (builds == null) return;
        for (File dir : builds) {
            if (!dir.getName().equals(keep)) deleteRecursively(dir);
        }
    }

    private static void deleteRecursively(File file) {
        File[] children = file.listFiles();
        if (children != null) {
            for (File child : children) deleteRecursively(child);
        }
        //noinspection ResultOfMethodCallIgnored
        file.delete();
    }

    private static byte[] fetch(String url) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        try {
            conn.setConnectTimeout(FETCH_TIMEOUT_MS);
            conn.setReadTimeout(FETCH_TIMEOUT_MS);
            if (conn.getResponseCode() != 200) return null;
            try (InputStream in = conn.getInputStream()) {
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                byte[] chunk = new byte[8192];
                int read;
                while ((read = in.read(chunk)) != -1) out.write(chunk, 0, read);
                return out.toByteArray();
            }
        } finally {
            conn.disconnect();
        }
    }

    private static void write(File file, byte[] body) throws Exception {
        File parent = file.getParentFile();
        if (parent != null && !parent.exists() && !parent.mkdirs()) {
            throw new IllegalStateException("mkdirs failed: " + parent);
        }
        try (FileOutputStream out = new FileOutputStream(file)) {
            out.write(body);
        }
    }

    private static String mime(String name) {
        if (name.endsWith(".html")) return "text/html";
        if (name.endsWith(".js")) return "application/javascript";
        if (name.endsWith(".css")) return "text/css";
        if (name.endsWith(".woff2")) return "font/woff2";
        if (name.endsWith(".webmanifest")) return "application/manifest+json";
        if (name.endsWith(".png")) return "image/png";
        if (name.endsWith(".svg")) return "image/svg+xml";
        return "application/octet-stream";
    }

    private static String charset(String name) {
        return name.endsWith(".html") || name.endsWith(".js") || name.endsWith(".css")
                ? "utf-8"
                : null;
    }
}
