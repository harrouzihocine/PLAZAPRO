package com.plazapro.app;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;

import java.util.Map;

/**
 * Renders the system-tray notifications for FCM data messages (the backend
 * sends DATA-ONLY payloads so this code runs foreground or background and owns
 * the presentation end-to-end).
 *
 * Channels: "messages" (chat — high importance, heads-up + sound) and
 * "general" (every other domain notification). The payload `tag` stacks
 * notifications the Messenger way — one tray entry per conversation, replaced
 * in place by the newest message instead of piling up.
 *
 * Tapping routes back into the SPA: the intent carries the payload's `link`
 * (e.g. /chat/42); MainActivity stashes it for the web app to consume.
 */
final class PlazaPush {
    static final String CHANNEL_MESSAGES = "messages";
    static final String CHANNEL_GENERAL = "general";
    static final String EXTRA_LINK = "plaza_link";

    private PlazaPush() {}

    static void createChannels(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;

        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) return;

        NotificationChannel messages = new NotificationChannel(
                CHANNEL_MESSAGES, "Messages", NotificationManager.IMPORTANCE_HIGH);
        messages.setDescription("Chat messages");
        messages.enableVibration(true);
        manager.createNotificationChannel(messages);

        NotificationChannel general = new NotificationChannel(
                CHANNEL_GENERAL, "Notifications", NotificationManager.IMPORTANCE_DEFAULT);
        general.setDescription("Visits, payments, reminders and other updates");
        manager.createNotificationChannel(general);
    }

    static void show(Context context, Map<String, String> data) {
        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        if (!manager.areNotificationsEnabled()) return;

        String kind = value(data, "kind", "general");
        String title = value(data, "title", "PLAZA PRO");
        String body = value(data, "body", "");
        String link = value(data, "link", "");
        String tag = value(data, "tag", kind);
        String channel = "chat_message".equals(kind) ? CHANNEL_MESSAGES : CHANNEL_GENERAL;

        Intent intent = new Intent(context, MainActivity.class);
        intent.setAction(Intent.ACTION_VIEW);
        // Unique data URI per tag → Android keeps the PendingIntents distinct;
        // FLAG_UPDATE_CURRENT refreshes the link when a tag is replaced.
        intent.setData(Uri.parse("plazapro://notification/" + Uri.encode(tag)));
        intent.putExtra(EXTRA_LINK, link);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);

        PendingIntent contentIntent = PendingIntent.getActivity(
                context, tag.hashCode(), intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channel)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(contentIntent)
                .setPriority("chat_message".equals(kind)
                        ? NotificationCompat.PRIORITY_HIGH
                        : NotificationCompat.PRIORITY_DEFAULT)
                .setCategory("chat_message".equals(kind)
                        ? NotificationCompat.CATEGORY_MESSAGE
                        : NotificationCompat.CATEGORY_SOCIAL);

        try {
            manager.notify(tag, 1, builder.build());
        } catch (SecurityException ignored) {
            // POST_NOTIFICATIONS revoked between the check and the call — drop it.
        }
    }

    private static String value(Map<String, String> data, String key, String fallback) {
        String v = data.get(key);
        return (v == null || v.isEmpty()) ? fallback : v;
    }
}
