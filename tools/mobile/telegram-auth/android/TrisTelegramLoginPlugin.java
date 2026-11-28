package eu.trisservice.academy.dev;

import android.net.Uri;
import android.os.Handler;
import android.os.Looper;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.util.Collections;
import kotlin.Unit;
import org.telegram.login.TelegramLogin;

@CapacitorPlugin(name = "TrisTelegramLogin")
public class TrisTelegramLoginPlugin extends Plugin {
    private static final String CLIENT_ID = "8262627457";
    private static final String HOST = "app337549632-login.tg.dev";
    private static final String REDIRECT = "https://" + HOST + "/tglogin";
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static TrisTelegramLoginPlugin active;
    private PluginCall pending;
    private boolean exchanging;
    private final Runnable timeout = () -> reject("LOGIN_TIMEOUT");

    static boolean isCallback(Uri uri) {
        return uri != null && "https".equals(uri.getScheme()) && HOST.equals(uri.getHost())
            && (uri.getPort() == -1 || uri.getPort() == 443) && "/tglogin".equals(uri.getPath())
            && uri.getUserInfo() == null;
    }

    private boolean isLocalLoginPage() {
        String url = getBridge().getWebView().getUrl();
        if (url == null) return false;
        Uri uri = Uri.parse(url);
        return "http".equals(uri.getScheme()) && "127.0.0.1".equals(uri.getHost())
            && uri.getPort() == 8765 && "/login".equals(uri.getPath());
    }

    @PluginMethod
    public void login(PluginCall call) {
        getActivity().runOnUiThread(() -> {
            if (!isLocalLoginPage() || !CLIENT_ID.equals(call.getString("clientId"))) {
                call.reject("INVALID_DEV_ORIGIN_OR_CLIENT");
                return;
            }
            if (active != null) {
                call.reject("LOGIN_ALREADY_RUNNING");
                return;
            }
            active = this;
            pending = call;
            exchanging = false;
            MAIN.postDelayed(timeout, 300000);
            try {
                TelegramLogin.init(CLIENT_ID, REDIRECT, Collections.singletonList("profile"));
                TelegramLogin.startLogin(getActivity());
            } catch (Exception ignored) {
                reject("TELEGRAM_START_FAILED");
            }
        });
    }

    static void acceptCallback(Uri uri) {
        if (!isCallback(uri)) return;
        TrisTelegramLoginPlugin plugin = active;
        if (plugin == null || plugin.pending == null || plugin.exchanging) return;
        if (!plugin.isLocalLoginPage()) {
            plugin.reject("LOGIN_PAGE_CHANGED");
            return;
        }
        plugin.exchanging = true;
        PluginCall expected = plugin.pending;
        try {
            TelegramLogin.handleLoginResponse(uri, data -> {
                if (plugin.pending == expected && plugin.isLocalLoginPage()) {
                    String token = data.getIdToken();
                    if (token == null || token.isEmpty()) {
                        plugin.reject("EMPTY_ID_TOKEN");
                    } else {
                        JSObject result = new JSObject();
                        result.put("idToken", token);
                        plugin.clear();
                        expected.resolve(result);
                    }
                } else if (plugin.pending == expected) {
                    plugin.reject("LOGIN_PAGE_CHANGED");
                }
                return Unit.INSTANCE;
            }, error -> {
                if (plugin.pending == expected) plugin.reject("TELEGRAM_LOGIN_FAILED");
                return Unit.INSTANCE;
            });
        } catch (Exception ignored) {
            plugin.reject("TELEGRAM_CALLBACK_FAILED");
        }
    }

    private void clear() {
        MAIN.removeCallbacks(timeout);
        pending = null;
        exchanging = false;
        if (active == this) active = null;
    }

    private void reject(String code) {
        PluginCall call = pending;
        clear();
        if (call != null) call.reject(code);
    }

    @Override
    protected void handleOnDestroy() {
        if (active == this) reject("ACTIVITY_DESTROYED");
        super.handleOnDestroy();
    }
}
