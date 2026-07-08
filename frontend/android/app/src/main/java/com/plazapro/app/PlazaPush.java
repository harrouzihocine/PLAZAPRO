package com.plazapro.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.service.notification.StatusBarNotification;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.Person;
import androidx.core.app.RemoteInput;

import java.util.Map;

/**
 * Renders the system-tray notifications for FCM data messages (the backend
 * sends DATA-ONLY payloads so this code runs foreground or background and owns
 * the presentation end-to-end).
 *
 * Channels: "messages" (chat — high importance, heads-up + sound) and
 * "general" (every other domain notification). The payload `tag` stacks
 * notifications the Messenger way — one tray entry per conversation.
 *
 * Chat entries are MessagingStyle conversations: consecutive messages append
 * to the same tray entry instead of replacing each other, and (API 24+) a
 * Reply action lets the user answer inline — ReplyReceiver posts the text to
 * the backend with the WebView's own session and appends it as "You".
 *
 * Tapping routes back into the SPA: the intent carries the payload's `link`
 * (e.g. /chat/42); MainActivity stashes it for the web app to consume.
 */
final class PlazaPush {
    static final String CHANNEL_MESSAGES = "messages";
    static final String CHANNEL_GENERAL = "general";
    static final String EXTRA_LINK = "plaza_link";
    static final String EXTRA_CONVERSATION_ID = "plaza_conversation_id";
    static final String EXTRA_TAG = "plaza_tag";
    static final String EXTRA_TITLE = "plaza_title";

    /** All tray entries share this id; the string tag tells them apart. */
    private static final int NOTIFICATION_ID = 1;

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
        String kind = value(data, "kind", "general");
        String title = value(data, "title", "PLAZA PRO");
        String body = value(data, "body", "");
        String link = value(data, "link", "");
        String tag = value(data, "tag", kind);

        if ("chat_message".equals(kind)) {
            // subject_id arrived with quick-reply support; older backend
            // payloads still carry the conversation id inside the tag.
            String conversationId = value(data, "subject_id",
                    tag.startsWith("chat-") ? tag.substring("chat-".length()) : "");
            showChatMessage(context, tag, conversationId, title, body, false);
            return;
        }

        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        if (!manager.areNotificationsEnabled()) return;

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_GENERAL)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(contentIntent(context, tag, link))
                .setPriority(NotificationCompat.PRIORITY_DEFAULT)
                .setCategory(NotificationCompat.CATEGORY_SOCIAL);

        try {
            manager.notify(tag, NOTIFICATION_ID, builder.build());
        } catch (SecurityException ignored) {
            // POST_NOTIFICATIONS revoked between the check and the call — drop it.
        }
    }

    /**
     * Show/refresh a conversation's tray entry. Appends to the running
     * MessagingStyle transcript when one is on screen (Messenger-style
     * stacking); `fromSelf` renders the message as "You" (a quick reply) and
     * updates silently instead of chiming again.
     */
    static void showChatMessage(Context context, String tag, String conversationId,
                                String title, CharSequence text, boolean fromSelf) {
        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        if (!manager.areNotificationsEnabled()) return;

        NotificationCompat.MessagingStyle style = activeStyle(context, tag);
        if (style == null) {
            style = new NotificationCompat.MessagingStyle(
                    new Person.Builder().setName("You").build());
        }
        Person sender = fromSelf ? null : new Person.Builder().setName(title).build();
        style.addMessage(text, System.currentTimeMillis(), sender);

        String link = "/chat/" + conversationId;
        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_MESSAGES)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle(title)
                .setContentText(text)
                .setStyle(style)
                .setAutoCancel(true)
                .setContentIntent(contentIntent(context, tag, link))
                .setOnlyAlertOnce(fromSelf)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setCategory(NotificationCompat.CATEGORY_MESSAGE);

        NotificationCompat.Action reply = replyAction(context, tag, conversationId, title);
        if (reply != null) {
            builder.addAction(reply);
        }

        try {
            manager.notify(tag, NOTIFICATION_ID, builder.build());
        } catch (SecurityException ignored) {
            // POST_NOTIFICATIONS revoked between the check and the call — drop it.
        }
    }

    /**
     * A reply failed after the tray entry's spinner started: re-post the entry
     * unchanged (that clears the spinner) and raise a separate notice so the
     * user knows to open the app. Called off the main thread by ReplyReceiver.
     */
    static void showReplyFailed(Context context, String tag, String conversationId, String title) {
        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        if (!manager.areNotificationsEnabled()) return;

        NotificationCompat.MessagingStyle style = activeStyle(context, tag);
        if (style != null) {
            NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_MESSAGES)
                    .setSmallIcon(R.drawable.ic_stat_notify)
                    .setContentTitle(title)
                    .setStyle(style)
                    .setAutoCancel(true)
                    .setContentIntent(contentIntent(context, tag, "/chat/" + conversationId))
                    .setOnlyAlertOnce(true)
                    .setPriority(NotificationCompat.PRIORITY_HIGH)
                    .setCategory(NotificationCompat.CATEGORY_MESSAGE);
            NotificationCompat.Action reply = replyAction(context, tag, conversationId, title);
            if (reply != null) {
                builder.addAction(reply);
            }
            try {
                manager.notify(tag, NOTIFICATION_ID, builder.build());
            } catch (SecurityException ignored) {
            }
        }

        NotificationCompat.Builder failure = new NotificationCompat.Builder(context, CHANNEL_GENERAL)
                .setSmallIcon(R.drawable.ic_stat_notify)
                .setContentTitle("Reply not sent")
                .setContentText("Couldn't send your reply to " + title + " — tap to open the chat.")
                .setAutoCancel(true)
                .setContentIntent(contentIntent(context, tag + ":fail", "/chat/" + conversationId))
                .setPriority(NotificationCompat.PRIORITY_DEFAULT)
                .setCategory(NotificationCompat.CATEGORY_ERROR);

        try {
            manager.notify(tag + ":fail", NOTIFICATION_ID, failure.build());
        } catch (SecurityException ignored) {
        }
    }

    /** Tap → MainActivity with the SPA deep link stashed (see MainActivity). */
    private static PendingIntent contentIntent(Context context, String tag, String link) {
        Intent intent = new Intent(context, MainActivity.class);
        intent.setAction(Intent.ACTION_VIEW);
        // Unique data URI per tag → Android keeps the PendingIntents distinct;
        // FLAG_UPDATE_CURRENT refreshes the link when a tag is replaced.
        intent.setData(Uri.parse("plazapro://notification/" + Uri.encode(tag)));
        intent.putExtra(EXTRA_LINK, link);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);

        return PendingIntent.getActivity(
                context, tag.hashCode(), intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /**
     * The inline "Reply" (RemoteInput → ReplyReceiver). Needs API 24+ for
     * direct reply and a conversation id to post to; otherwise no action —
     * tapping the notification still opens the thread.
     */
    private static NotificationCompat.Action replyAction(
            Context context, String tag, String conversationId, String title) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.N) return null;
        if (conversationId == null || conversationId.isEmpty()) return null;

        Intent intent = new Intent(context, ReplyReceiver.class)
                .setAction(ReplyReceiver.ACTION_REPLY)
                .setData(Uri.parse("plazapro://reply/" + Uri.encode(tag)))
                .putExtra(EXTRA_CONVERSATION_ID, conversationId)
                .putExtra(EXTRA_TAG, tag)
                .putExtra(EXTRA_TITLE, title);

        // MUTABLE is required: the OS must be able to attach the typed text.
        PendingIntent pending = PendingIntent.getBroadcast(
                context, tag.hashCode(), intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_MUTABLE);

        RemoteInput input = new RemoteInput.Builder(ReplyReceiver.KEY_TEXT)
                .setLabel("Reply")
                .build();

        return new NotificationCompat.Action.Builder(
                        R.drawable.ic_stat_notify, "Reply", pending)
                .addRemoteInput(input)
                .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_REPLY)
                .setAllowGeneratedReplies(true)
                .build();
    }

    /**
     * The MessagingStyle of this conversation's on-screen entry, so a new
     * message appends to the transcript instead of wiping it. Null when the
     * entry was dismissed (or predates MessagingStyle) — callers start fresh.
     */
    private static NotificationCompat.MessagingStyle activeStyle(Context context, String tag) {
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) return null;

        try {
            for (StatusBarNotification active : manager.getActiveNotifications()) {
                if (active.getId() == NOTIFICATION_ID && tag.equals(active.getTag())) {
                    Notification notification = active.getNotification();
                    return NotificationCompat.MessagingStyle
                            .extractMessagingStyleFromNotification(notification);
                }
            }
        } catch (Exception ignored) {
            // Some OEM ROMs throw on getActiveNotifications — start fresh.
        }
        return null;
    }

    private static String value(Map<String, String> data, String key, String fallback) {
        String v = data.get(key);
        return (v == null || v.isEmpty()) ? fallback : v;
    }
}
