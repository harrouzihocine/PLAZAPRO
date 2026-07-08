package com.plazapro.app;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;

/**
 * The notification-tap trampoline for click-to-call (kind=call_request): opens
 * the default dialer with the client's number pre-filled and reports the dial
 * to the backend, which raises the cross-device "log this call?" prompt.
 *
 * An ACTIVITY on purpose: Android 12+ blocks notification trampolines that go
 * through services or broadcast receivers, but a direct activity PendingIntent
 * may still both start the dialer and do its bookkeeping. Theme.NoDisplay +
 * finish() inside onCreate — the user only ever sees the dialer. ACTION_DIAL
 * needs no runtime permission: the user presses the green button themselves.
 */
public class CallTrampolineActivity extends Activity {
    static final String EXTRA_PHONE = "plaza_phone";
    static final String EXTRA_CALL_REQUEST_ID = "plaza_call_request_id";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        String phone = getIntent().getStringExtra(EXTRA_PHONE);
        String requestId = getIntent().getStringExtra(EXTRA_CALL_REQUEST_ID);

        // Best-effort, off the main thread — the dialer in front keeps this
        // process cached long enough. If it fails (offline), the call still
        // happens; only the log reminder is lost, and the agent can always log
        // from the client file as usual.
        if (requestId != null && !requestId.isEmpty()) {
            new Thread(() -> {
                try {
                    PlazaApi.post(getApplicationContext(),
                            "/call-requests/" + Uri.encode(requestId) + "/dialed", null, null);
                } catch (Exception ignored) {
                }
            }, "plaza-dialed").start();
        }

        if (phone != null && !phone.isEmpty()) {
            Intent dial = new Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(phone)));
            dial.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            try {
                startActivity(dial);
            } catch (Exception ignored) {
                // No dialer app (wifi-only tablet) — nothing sensible to do.
            }
        }

        finish();
    }
}
