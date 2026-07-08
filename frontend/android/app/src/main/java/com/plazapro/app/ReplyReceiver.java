package com.plazapro.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;

import androidx.core.app.RemoteInput;

import org.json.JSONObject;

import java.util.UUID;

/**
 * Handles the notification's inline "Reply": posts the typed text to
 * POST /api/v1/conversations/{id}/messages without opening the app.
 *
 * Auth rides the WebView's own session via PlazaApi (cookies + XSRF echo).
 * The route is idempotent-guarded, so the key is sent from the first attempt
 * (same convention as the web outbox).
 *
 * Outcome feeds back into the tray entry (which shows a spinner until the
 * same tag is re-notified): success appends the reply as "You"; failure
 * restores the entry and raises a "Reply not sent — open the chat" notice.
 */
public class ReplyReceiver extends BroadcastReceiver {
    static final String ACTION_REPLY = "com.plazapro.app.REPLY";
    static final String KEY_TEXT = "plaza_reply_text";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (!ACTION_REPLY.equals(intent.getAction())) return;

        Bundle results = RemoteInput.getResultsFromIntent(intent);
        CharSequence typed = results == null ? null : results.getCharSequence(KEY_TEXT);
        String text = typed == null ? "" : typed.toString().trim();
        String conversationId = intent.getStringExtra(PlazaPush.EXTRA_CONVERSATION_ID);
        String tag = intent.getStringExtra(PlazaPush.EXTRA_TAG);
        String title = intent.getStringExtra(PlazaPush.EXTRA_TITLE);

        if (text.isEmpty() || conversationId == null || conversationId.isEmpty()
                || tag == null || tag.isEmpty()) {
            return;
        }

        // Network off the main thread; goAsync keeps the process alive for it.
        PendingResult pending = goAsync();
        Context app = context.getApplicationContext();
        String safeTitle = title == null || title.isEmpty() ? "PLAZA PRO" : title;

        new Thread(() -> {
            boolean sent;
            try {
                sent = postReply(app, conversationId, text);
            } catch (Exception e) {
                sent = false;
            }

            if (sent) {
                PlazaPush.showChatMessage(app, tag, conversationId, safeTitle, text, true);
            } else {
                PlazaPush.showReplyFailed(app, tag, conversationId, safeTitle);
            }
            pending.finish();
        }, "plaza-reply").start();
    }

    private boolean postReply(Context context, String conversationId, String text) throws Exception {
        return PlazaApi.post(context,
                "/conversations/" + conversationId + "/messages",
                new JSONObject().put("body", text),
                UUID.randomUUID().toString());
    }
}
