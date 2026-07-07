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
        if (MainActivity.isInForeground()) return;

        PlazaPush.show(this, message.getData());
    }
}
