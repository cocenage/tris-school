// TRIS DEV NATIVE TELEGRAM BRIDGE — generated from tools/mobile/telegram-auth/android.
package eu.trisservice.academy.dev;

import android.content.Intent;
import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(TrisTelegramLoginPlugin.class);
        super.onCreate(savedInstanceState);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        if (intent != null && TrisTelegramLoginPlugin.isCallback(intent.getData())) {
            // Do not propagate the authorization code to other bridge plugins.
            super.onNewIntent(new Intent(intent).setData(null));
            TrisTelegramLoginPlugin.acceptCallback(intent.getData());
            setIntent(new Intent(this, MainActivity.class));
            return;
        }
        super.onNewIntent(intent);
    }
}
