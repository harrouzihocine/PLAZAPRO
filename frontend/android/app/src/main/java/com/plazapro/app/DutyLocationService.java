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

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

import org.json.JSONObject;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * The on-duty location share as a FOREGROUND service — DEMAND-DRIVEN, because
 * continuous GPS eats a field phone's battery:
 *
 *   idle       network/passive provider only, one coarse fix ~5 min — cell/
 *              wifi positioning, near-zero battery ("roughly where");
 *   precision  the real GPS listener, ONLY while it's needed:
 *                - the server says so (`precision:true` on a post response —
 *                  an en-route leg is live, geofence + ETA need it), or
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

    // Idle: coarse and slow. Precision: tight enough for a moving car.
    private static final long IDLE_UPDATE_MS = 300_000;
    private static final float IDLE_UPDATE_M = 200f;
    private static final long GPS_UPDATE_MS = 20_000;
    private static final float GPS_UPDATE_M = 25f;
    private static final long IDLE_MIN_POST_MS = 240_000;
    private static final long PRECISION_MIN_POST_MS = 20_000;
    private static final float MIN_POST_M = 30f;
    private static final long BURST_MS = 120_000;

    private static volatile DutyLocationService instance;

    private LocationManager locationManager;
    private ExecutorService poster;
    private boolean gpsListening = false;
    private volatile boolean serverWantsPrecision = false;
    private volatile long burstUntilMs = 0;
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
    static void requestBurst() {
        DutyLocationService running = instance;
        if (running == null) return;
        running.burstUntilMs = System.currentTimeMillis() + BURST_MS;
        running.syncGpsListener();
        running.requestOneGpsFix();
    }

    static boolean hasLocationPermission(Context context) {
        return ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
    }

    private boolean precisionActive() {
        return serverWantsPrecision || System.currentTimeMillis() < burstUntilMs;
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
            try {
                // The idle diet: coarse network fixes, plus free passive ones
                // whenever another app happens to use GPS.
                if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.NETWORK_PROVIDER, IDLE_UPDATE_MS, IDLE_UPDATE_M, this);
                }
                locationManager.requestLocationUpdates(
                        LocationManager.PASSIVE_PROVIDER, IDLE_UPDATE_MS, IDLE_UPDATE_M, this);
                // One opening fix so the dispatcher sees the agent right away.
                requestOneGpsFix();
            } catch (SecurityException e) {
                stopSelf();
                return START_NOT_STICKY;
            }
        }

        instance = this;
        return START_STICKY;
    }

    /** Register/unregister the battery-expensive GPS listener as need changes. */
    private synchronized void syncGpsListener() {
        if (locationManager == null || !hasLocationPermission(this)) return;
        boolean wanted = precisionActive();
        try {
            if (wanted && !gpsListening
                    && locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                locationManager.requestLocationUpdates(
                        LocationManager.GPS_PROVIDER, GPS_UPDATE_MS, GPS_UPDATE_M, this);
                gpsListening = true;
            } else if (!wanted && gpsListening) {
                // Drop ONLY the GPS stream; re-register the idle diet.
                locationManager.removeUpdates(this);
                gpsListening = false;
                if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.NETWORK_PROVIDER, IDLE_UPDATE_MS, IDLE_UPDATE_M, this);
                }
                locationManager.requestLocationUpdates(
                        LocationManager.PASSIVE_PROVIDER, IDLE_UPDATE_MS, IDLE_UPDATE_M, this);
            }
        } catch (SecurityException ignored) {
        }
    }

    @SuppressWarnings("deprecation")
    private void requestOneGpsFix() {
        if (locationManager == null || !hasLocationPermission(this)) return;
        try {
            if (locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                locationManager.requestSingleUpdate(LocationManager.GPS_PROVIDER, this, null);
            } else if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                locationManager.requestSingleUpdate(LocationManager.NETWORK_PROVIDER, this, null);
            }
        } catch (SecurityException ignored) {
        }
    }

    @Override
    public void onLocationChanged(@NonNull Location location) {
        long now = System.currentTimeMillis();
        long minGap = precisionActive() ? PRECISION_MIN_POST_MS : IDLE_MIN_POST_MS;
        boolean due = now - lastPostMs >= minGap;
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
                if (result.status == 409) {
                    stopSelf();
                    return;
                }
                if (result.status >= 200 && result.status < 300) {
                    // The server's cue: a live en-route leg keeps GPS on.
                    boolean wants = result.body.contains("\"precision\":true");
                    if (wants != serverWantsPrecision) {
                        serverWantsPrecision = wants;
                        syncGpsListener();
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
        if (locationManager != null) locationManager.removeUpdates(this);
        if (poster != null) poster.shutdown();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
