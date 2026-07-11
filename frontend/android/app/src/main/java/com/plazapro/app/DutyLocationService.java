package com.plazapro.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ServiceInfo;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.IBinder;
import android.os.Looper;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;
import androidx.core.location.LocationManagerCompat;

import org.json.JSONObject;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * The on-duty location share as a FOREGROUND service — DEMAND-DRIVEN, because
 * continuous GPS eats a field phone's battery:
 *
 *   idle       one cheap SNAPSHOT fix per interval (server-paced via the
 *              `snapshot_s` cue on each post response, default 5 min, 0 = off)
 *              so the dispatcher's day replay shows the whole path; between
 *              snapshots there is NO listener at all — the OS location
 *              indicator only blinks for the seconds a fix takes (v2.0
 *              reverses the v1.8 zero-idle rule by the owner's call:
 *              full-day paths beat a dark indicator);
 *   precision  listeners registered ONLY while needed, dropped after:
 *                - the app said an en-route leg started (bridge
 *                  setDutyPrecision), confirmed by the server's
 *                  `precision` cue on each post response, or
 *                - a dispatcher actually looked (locate_request FCM →
 *                  requestBurst(): 2 minutes of GPS + one immediate fix).
 *
 * Same privacy contract as v1.6: runs only between the My Day duty switch's
 * start/stop, the ongoing notification is the visible indicator, a 409
 * ("off duty") stops the service, no boot receiver. Auth rides the WebView
 * session via PlazaApi, like the tray quick-reply.
 */
public class DutyLocationService extends Service implements LocationListener {
    private static final String CHANNEL_ID = "duty";
    private static final int NOTIFICATION_ID = 4001;

    // Precision cadence: tight enough for a moving car.
    private static final long GPS_UPDATE_MS = 20_000;
    private static final float GPS_UPDATE_M = 25f;
    private static final long PRECISION_MIN_POST_MS = 20_000;
    private static final float MIN_POST_M = 30f;
    private static final long BURST_MS = 120_000;

    // Path snapshots: one single-shot fix per interval, server-adjustable.
    private static final long DEFAULT_SNAPSHOT_MS = 300_000;
    private static final long FIRST_SNAPSHOT_MS = 5_000; // the path starts at duty-on

    private static volatile DutyLocationService instance;
    /** A locate ping that arrived while the service was dead (OS killed it). */
    private static volatile boolean pendingBurst = false;

    private static final long LOCATION_CHECK_MS = 180_000;

    private LocationManager locationManager;
    private ExecutorService poster;
    private final android.os.Handler handler = new android.os.Handler(android.os.Looper.getMainLooper());
    private boolean listening = false;
    private volatile boolean appWantsPrecision = false; // My Day: a leg is en route
    private volatile boolean serverWantsPrecision = false;
    private volatile long burstUntilMs = 0;
    private volatile long snapshotMs = DEFAULT_SNAPSHOT_MS; // 0 = server said off
    private boolean snapshotScheduled = false;
    private long lastPostMs = 0;
    private Location lastPosted = null;

    static void start(Context context) {
        Intent intent = new Intent(context, DutyLocationService.class);
        ContextCompat.startForegroundService(context, intent);
    }

    static void stop(Context context) {
        context.stopService(new Intent(context, DutyLocationService.class));
    }

    /**
     * A dispatcher looked (locate_request push): two minutes of real GPS and
     * one immediate fix. No-op when off duty — the service isn't running.
     */
    static void requestBurst(Context context) {
        DutyLocationService running = instance;
        if (running == null) {
            // The OS killed the service but the server says this agent is on
            // duty (locate pings only go to on-duty agents) — heal: restart
            // and burst as soon as it is up. Android 12+ may refuse a
            // foreground start from the background (normal-priority FCM,
            // Android 14 location limits) — a refusal must not crash the
            // FCM handler; the next app open restarts the service anyway.
            if (hasLocationPermission(context)) {
                pendingBurst = true;
                try {
                    start(context);
                } catch (Exception ignored) {
                }
            }
            return;
        }
        // FCM delivers on a background thread with no Looper — every
        // LocationManager call must run on the main one, or the registration
        // dies silently and the dispatcher never gets the fix (the v1.8 bug).
        running.handler.post(() -> {
            running.burstUntilMs = System.currentTimeMillis() + BURST_MS;
            running.syncListeners();
            running.requestOneFix();
            // Nothing re-evaluates on its own once posts stop — drop the
            // listeners the moment the burst window closes.
            running.handler.postDelayed(running::syncListeners, BURST_MS + 1000);
        });
    }

    /** My Day's en-route flag, via the JS bridge — precision follows the work. */
    static void setPrecision(boolean wanted) {
        DutyLocationService running = instance;
        if (running == null) return;
        running.handler.post(() -> {
            running.appWantsPrecision = wanted;
            running.syncListeners();
        });
    }

    /** Device-level location toggle (not the app permission). */
    static boolean isLocationEnabled(Context context) {
        LocationManager lm = (LocationManager) context.getSystemService(Context.LOCATION_SERVICE);
        return lm != null && LocationManagerCompat.isLocationEnabled(lm);
    }

    static boolean hasLocationPermission(Context context) {
        return ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
    }

    private boolean precisionActive() {
        return appWantsPrecision || serverWantsPrecision
                || System.currentTimeMillis() < burstUntilMs;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        Notification notification = buildNotification();
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
        } else {
            startForeground(NOTIFICATION_ID, notification);
        }

        if (!hasLocationPermission(this)) {
            stopSelf();
            return START_NOT_STICKY;
        }

        if (locationManager == null) {
            locationManager = (LocationManager) getSystemService(Context.LOCATION_SERVICE);
            poster = Executors.newSingleThreadExecutor();
            // Deliberately NO listener and NO opening fix here: idle duty
            // costs nothing and shows no location indicator. Precision starts
            // when a leg goes en route or a dispatcher pings.
        }

        instance = this;

        // On duty with device location OFF is a lie on the dispatch board —
        // watch the toggle (a settings read, zero battery) and pull the plug
        // through the server (it notifies the agent AND the dispatchers).
        handler.removeCallbacks(locationCheck);
        handler.post(locationCheck);

        // Path snapshots: the first fix lands moments after duty-on so the
        // trail starts where the day did; then one per interval.
        handler.removeCallbacks(snapshotTick);
        snapshotScheduled = true;
        handler.postDelayed(snapshotTick, FIRST_SNAPSHOT_MS);

        if (pendingBurst) {
            pendingBurst = false;
            handler.post(() -> requestBurst(this));
        }

        return START_STICKY;
    }

    /**
     * One cheap fix per interval while idle (precision windows already post
     * plenty). The server re-paces the loop via `snapshot_s` on each post
     * response; 0 parks it until a later response revives it.
     */
    private final Runnable snapshotTick = new Runnable() {
        @Override
        public void run() {
            if (snapshotMs <= 0) {
                snapshotScheduled = false;
                return;
            }
            if (!precisionActive()) requestOneFix();
            handler.postDelayed(this, snapshotMs);
        }
    };

    private final Runnable locationCheck = new Runnable() {
        @Override
        public void run() {
            if (!isLocationEnabled(DutyLocationService.this)) {
                poster.execute(() -> {
                    try {
                        PlazaApi.post(DutyLocationService.this, "/me/duty/location-lost", null, null);
                    } catch (Exception ignored) {
                    }
                });
                stopSelf();
                return;
            }
            handler.postDelayed(this, LOCATION_CHECK_MS);
        }
    };

    /** Listeners exist ONLY while precision is needed; idle holds none at all. */
    private synchronized void syncListeners() {
        if (locationManager == null || !hasLocationPermission(this)) return;
        boolean wanted = precisionActive();
        try {
            if (wanted && !listening) {
                if (locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.GPS_PROVIDER, GPS_UPDATE_MS, GPS_UPDATE_M, this,
                            Looper.getMainLooper());
                }
                if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.NETWORK_PROVIDER, GPS_UPDATE_MS, GPS_UPDATE_M, this,
                            Looper.getMainLooper());
                }
                listening = true;
            } else if (!wanted && listening) {
                locationManager.removeUpdates(this);
                listening = false;
            }
        } catch (SecurityException ignored) {
        }
    }

    @SuppressWarnings("deprecation")
    private void requestOneFix() {
        if (locationManager == null || !hasLocationPermission(this)) return;
        try {
            // Network first (fast, indoors-friendly) AND GPS (accurate outside)
            // — the throttle in onLocationChanged collapses duplicates.
            if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                locationManager.requestSingleUpdate(
                        LocationManager.NETWORK_PROVIDER, this, Looper.getMainLooper());
            }
            if (locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                locationManager.requestSingleUpdate(
                        LocationManager.GPS_PROVIDER, this, Looper.getMainLooper());
            }
        } catch (SecurityException ignored) {
        }
    }

    @Override
    public void onLocationChanged(@NonNull Location location) {
        long now = System.currentTimeMillis();
        boolean due = now - lastPostMs >= PRECISION_MIN_POST_MS;
        boolean moved = lastPosted == null || location.distanceTo(lastPosted) >= MIN_POST_M;
        if (!due && !moved) return;

        lastPostMs = now;
        lastPosted = location;

        poster.execute(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("latitude", Math.round(location.getLatitude() * 1e7) / 1e7);
                body.put("longitude", Math.round(location.getLongitude() * 1e7) / 1e7);
                if (location.hasAccuracy()) {
                    body.put("accuracy_m", Math.min(65000, Math.round(location.getAccuracy())));
                }
                PlazaApi.Result result = PlazaApi.postForResult(this, "/me/positions", body, null);
                // 409 = the server ended duty; 401 = the session died (logout,
                // expiry) and no later post can succeed — either way this
                // service has no business running. Duty-on at the next login
                // starts it again (useDutyTracking's apply()).
                if (result.status == 409 || result.status == 401) {
                    stopSelf();
                    return;
                }
                if (result.status >= 200 && result.status < 300) {
                    // The server's cue: a live en-route leg keeps GPS on.
                    boolean wants = result.body.contains("\"precision\":true");
                    if (wants != serverWantsPrecision) {
                        serverWantsPrecision = wants;
                        syncListeners();
                    }
                    // …and the snapshot pace (settings change = no APK).
                    java.util.regex.Matcher m = java.util.regex.Pattern
                            .compile("\"snapshot_s\":(\\d+)").matcher(result.body);
                    if (m.find()) {
                        long ms = Long.parseLong(m.group(1)) * 1000L;
                        if (ms != snapshotMs) {
                            snapshotMs = ms;
                            handler.post(() -> {
                                if (snapshotMs > 0 && !snapshotScheduled) {
                                    snapshotScheduled = true;
                                    handler.postDelayed(snapshotTick, snapshotMs);
                                }
                            });
                        }
                    }
                }
            } catch (Exception ignored) {
            }
        });
    }

    private Notification buildNotification() {
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= 26 && manager.getNotificationChannel(CHANNEL_ID) == null) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID, "On duty", NotificationManager.IMPORTANCE_LOW);
            channel.setDescription("Shown while you share your position with dispatch.");
            channel.setShowBadge(false);
            manager.createNotificationChannel(channel);
        }

        Intent open = new Intent(this, MainActivity.class);
        open.putExtra(PlazaPush.EXTRA_LINK, "/my-day");
        PendingIntent tap = PendingIntent.getActivity(
                this, 0, open,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        return new NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(R.mipmap.ic_launcher)
                .setContentTitle("On duty")
                .setContentText("Sharing your position with dispatch — switch off in My Day.")
                .setOngoing(true)
                .setSilent(true)
                .setContentIntent(tap)
                .build();
    }

    @Override
    public void onDestroy() {
        instance = null;
        // Everything pending, including the burst-window closers posted as
        // method references (removeCallbacks can't match those by instance).
        handler.removeCallbacksAndMessages(null);
        snapshotScheduled = false;
        if (locationManager != null) locationManager.removeUpdates(this);
        listening = false;
        if (poster != null) poster.shutdown();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
