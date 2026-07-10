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
 * The on-duty location share as a FOREGROUND service, so a field agent's fixes
 * keep flowing to /api/v1/me/positions while the phone is pocketed and the
 * WebView is suspended (the web layer's watchPosition is foreground-only).
 *
 * The contract mirrors the server's privacy rules exactly:
 *  - runs ONLY between PlazaNativeBridge.startDutyTracking() (the My Day duty
 *    switch) and stopDutyTracking() — never self-starts, no boot receiver;
 *  - the ongoing notification is the visible tracking indicator Android
 *    mandates — and the transparency the owner promised the team;
 *  - a 409 from the server means "off duty" (toggled elsewhere / session swept):
 *    the service stops itself rather than argue.
 *
 * Cadence mirrors useDutyTracking: the OS wakes us at ≥45 s / ≥25 m, and a fix
 * is posted when 60 s passed or the device moved ≥30 m. Auth rides the
 * WebView's persisted session via PlazaApi, same as the tray quick-reply.
 */
public class DutyLocationService extends Service implements LocationListener {
    private static final String CHANNEL_ID = "duty";
    private static final int NOTIFICATION_ID = 4001;
    private static final long MIN_UPDATE_MS = 45_000;
    private static final float MIN_UPDATE_M = 25f;
    private static final long MIN_POST_MS = 60_000;
    private static final float MIN_POST_M = 30f;

    private LocationManager locationManager;
    private ExecutorService poster;
    private long lastPostMs = 0;
    private Location lastPosted = null;

    static void start(Context context) {
        Intent intent = new Intent(context, DutyLocationService.class);
        ContextCompat.startForegroundService(context, intent);
    }

    static void stop(Context context) {
        context.stopService(new Intent(context, DutyLocationService.class));
    }

    static boolean hasLocationPermission(Context context) {
        return ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        // Foreground within the 5 s the OS allows, with the location type
        // declared (required from Android 14).
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
                // Both providers: GPS for the road, network for indoors/urban
                // canyons. Duplicates collapse in the post throttle below.
                if (locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.GPS_PROVIDER, MIN_UPDATE_MS, MIN_UPDATE_M, this);
                }
                if (locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                    locationManager.requestLocationUpdates(
                            LocationManager.NETWORK_PROVIDER, MIN_UPDATE_MS, MIN_UPDATE_M, this);
                }
            } catch (SecurityException e) {
                stopSelf();
                return START_NOT_STICKY;
            }
        }

        // If the OS kills us mid-shift, come back — duty is still on server-side.
        return START_STICKY;
    }

    @Override
    public void onLocationChanged(@NonNull Location location) {
        long now = System.currentTimeMillis();
        boolean due = now - lastPostMs >= MIN_POST_MS;
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
                int status = PlazaApi.postForStatus(this, "/me/positions", body, null);
                if (status == 409) {
                    // Off duty server-side (toggled elsewhere / sweeper): obey and stop.
                    stopSelf();
                }
                // Network errors / 5xx: drop the fix, the next one retries.
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
        if (locationManager != null) locationManager.removeUpdates(this);
        if (poster != null) poster.shutdown();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
