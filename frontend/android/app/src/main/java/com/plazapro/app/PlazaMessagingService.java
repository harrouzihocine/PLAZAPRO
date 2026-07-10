package com.plazapro.app;

import androidx.annotation.NonNull;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

/**
 * Receives FCM messages for the shell. The backend sends data-only payloads,
 * so onMessageReceived runs whether the app is foregrounded, backgrounded or
 * cold — Facebook-style tray notifications with a deep link back into the SPA.
 *
 * Foreground is deliberately silent here: the web layer already shows its
 * in-app toast/dock pop with sound over the websocket; doubling it with a tray
 * entry would ring twice.
 */
public class PlazaMessagingService extends FirebaseMessagingService {

    @Override
    public void onNewToken(@NonNull String token) {
        // Persist for the web layer, which registers it with the backend on the
        // next boot/login via window.PlazaNative.getPushToken().
        PlazaNativeBridge.storeToken(this, token);
    }

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        if (message.getData().isEmpty()) return;

        // Click-to-call is the one kind that must ring even in the foreground:
        // its tap opens the DIALER, which the page itself cannot do — without
        // the tray entry a foregrounded phone would swallow the PC's click.
        // A dispatcher asked for a fresh fix: no tray line — the running duty
        // service answers with a short precision burst (no-op when off duty;
        // the ongoing "On duty" notification is the standing indicator).
        if ("locate_request".equals(message.getData().get("kind"))) {
            DutyLocationService.requestBurst();
            return;
        }

        boolean isCallRequest = "call_request".equals(message.getData().get("kind"));
        if (MainActivity.isInForeground() && !isCallRequest) return;

        PlazaPush.show(this, message.getData());
    }
}
