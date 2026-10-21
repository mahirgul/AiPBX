package com.mhrgl.aipbx.ui

import android.content.Context
import android.os.Bundle
import android.view.View
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.mhrgl.aipbx.util.L10n

/**
 * Base activity enforcing Android 15 (SDK 35+) edge-to-edge standards across the entire app.
 *
 * ARCHITECTURAL RULE (Android 15+ Edge-to-Edge Compliance):
 * 1. All UI activities MUST inherit from [BaseActivity].
 * 2. [enableEdgeToEdge] is invoked automatically before [super.onCreate] without custom
 *    deprecated parameters (such as [androidx.activity.SystemBarStyle.dark] or explicit bar colors).
 * 3. Never call deprecated window APIs:
 *    - Do NOT call Window.setDecorFitsSystemWindows or WindowCompat.setDecorFitsSystemWindows.
 *    - Do NOT call Window.setStatusBarColor or Window.setNavigationBarColor.
 *    - Do NOT set deprecated flags like FLAG_LAYOUT_NO_LIMITS.
 * 4. Use [applyEdgeToEdgeInsets] on root views to pad interactive UI away from system bars, cutouts, and IME.
 * 5. Multi-language wrap via [L10n.wrap] is automatically applied in [attachBaseContext].
 */
abstract class BaseActivity : AppCompatActivity() {

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(L10n.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
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
