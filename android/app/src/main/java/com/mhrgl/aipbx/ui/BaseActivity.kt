package com.mhrgl.aipbx.ui

import android.content.Context
import android.os.Build
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import com.mhrgl.aipbx.util.L10n

/**
 * Base activity of every screen: edge-to-edge without deprecated APIs.
 *
 * RULE (Google Play "Edge-to-edge may not display for all users" and "Your app
 * uses deprecated APIs or parameters for edge-to-edge" warnings):
 * 1. All UI activities MUST inherit from [BaseActivity].
 * 2. Do NOT call androidx enableEdgeToEdge() / EdgeToEdge.enable() /
 *    WindowCompat.enableEdgeToEdge(): their code for Android 8–14 calls
 *    Window.setStatusBarColor / setNavigationBarColor / setDecorFitsSystemWindows,
 *    which Play flags in the bundle even though it only runs on old devices.
 *    The app targets SDK 35+, so Android 15+ is edge-to-edge by the system;
 *    Android 8–14 keeps the classic (opaque) system bars of the theme.
 * 3. Never call deprecated window APIs or set bar colors (code or themes),
 *    no SystemBarStyle, no FLAG_LAYOUT_NO_LIMITS.
 * 4. Pad interactive UI with [applyEdgeToEdgeInsets] on the root view (insets are
 *    zero on Android 8–14, where the system keeps content out of the bars).
 * 5. Multi-language wrap via [L10n.wrap] is applied in [attachBaseContext].
 *
 * Enforced by EdgeToEdgeComplianceTest, bin/edge_to_edge_audit.sh and, on the
 * built release, bin/check_deprecated_window_apis.sh (CI fails on any hit).
 */
abstract class BaseActivity : AppCompatActivity() {

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(L10n.wrap(newBase))
    }

    /**
     * True for screens with a permanently dark background (call screens): on
     * Android 15+ the bars are transparent over the screen, so their icons are
     * kept light even when the phone uses the light theme.
     */
    protected open val darkSystemBars: Boolean = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (darkSystemBars && Build.VERSION.SDK_INT >= Build.VERSION_CODES.VANILLA_ICE_CREAM) {
            WindowCompat.getInsetsController(window, window.decorView).apply {
                isAppearanceLightStatusBars = false
                isAppearanceLightNavigationBars = false
            }
        }
    }

    /**
     * Applies standard system bar and display cutout insets as padding on [targetView].
     * If [consumeIme] is true, also handles the software keyboard (IME) insets.
     */
    protected fun applyEdgeToEdgeInsets(targetView: View, consumeIme: Boolean = false) {
        ViewCompat.setOnApplyWindowInsetsListener(targetView) { view, insets ->
            val systemBars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            val bottomPadding = if (consumeIme) {
                val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
                maxOf(systemBars.bottom, ime.bottom)
            } else {
                systemBars.bottom
            }
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, bottomPadding)
            insets
        }
    }
}
