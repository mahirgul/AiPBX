package com.mhrgl.aipbx.util

import android.content.Context
import android.content.res.Configuration
import android.os.Build
import androidx.annotation.StringRes
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences
import java.util.Locale

/**
 * App language: English by default, Turkish when the user picks it (login /
 * server screen or Settings). Activities follow it through AppCompat's
 * per-app locales; code without an Activity (ApiClient, services,
 * notifications) uses [str], because before Android 13 the application
 * context keeps the system language.
 */
object L10n {
    const val EN = "en"
    const val TR = "tr"
    private val SUPPORTED = listOf(EN, TR)

    /** Language names in their own language, so they are found whatever is active. */
    private val NAMES = mapOf(EN to "English", TR to "Türkçe")

    private lateinit var app: Context
    private var cachedLang: String? = null
    private var cachedContext: Context? = null

    fun init(context: Context) {
        app = context.applicationContext
        val prefs = AppPreferences.getInstance(app)
        // Android 13+: the language may also have been changed in the system's
        // per-app language settings; that choice wins.
        val system = AppCompatDelegate.getApplicationLocales()
        if (Build.VERSION.SDK_INT >= 33 && !system.isEmpty) {
            val lang = normalize(system[0]?.language)
            if (lang != prefs.appLanguage) prefs.appLanguage = lang
        }
        apply(prefs.appLanguage)
    }

    fun current(): String = normalize(AppPreferences.getInstance(app).appLanguage)

    fun displayName(lang: String = current()): String = NAMES[lang] ?: lang

    /** Switches the language; open activities are recreated by AppCompat. */
    fun set(lang: String) {
        val l = normalize(lang)
        AppPreferences.getInstance(app).appLanguage = l
        apply(l)
    }

    /** Localized string for code that has no Activity context. */
    fun str(@StringRes id: Int, vararg args: Any?): String = context().getString(id, *args)

    fun context(): Context {
        val lang = current()
        cachedContext?.takeIf { cachedLang == lang }?.let { return it }
        val cfg = Configuration(app.resources.configuration)
        cfg.setLocale(Locale.forLanguageTag(lang))
        return app.createConfigurationContext(cfg).also { cachedContext = it; cachedLang = lang }
    }

    /** English / Türkçe chooser used on the login, server and settings screens. */
    fun showPicker(activity: AppCompatActivity) {
        val idx = SUPPORTED.indexOf(current()).coerceAtLeast(0)
        AlertDialog.Builder(activity)
            .setTitle(activity.getString(R.string.language_title))
            .setSingleChoiceItems(SUPPORTED.map { displayName(it) }.toTypedArray(), idx) { dialog, which ->
                dialog.dismiss()
                if (SUPPORTED[which] != current()) set(SUPPORTED[which])
            }
            .setNegativeButton(activity.getString(R.string.btn_cancel), null)
            .show()
    }

    private fun apply(lang: String) {
        AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(lang))
    }

    private fun normalize(lang: String?): String = if (lang?.lowercase()?.startsWith(TR) == true) TR else EN
}
