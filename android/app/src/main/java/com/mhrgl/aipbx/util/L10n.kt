package com.mhrgl.aipbx.util

import android.app.LocaleManager
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
 * App language: English by default, or another supported language the user
 * picks (login / server screen or Settings). Activities follow it through AppCompat's
 * per-app locales; code without an Activity (ApiClient, services,
 * notifications) uses [str], because before Android 13 the application
 * context keeps the system language.
 */
object L10n {
    const val EN = "en"
    const val TR = "tr"

    /** Language names in their own language, so they are found whatever is active. */
    private val NAMES = linkedMapOf(
        EN to "English",
        TR to "Türkçe",
        "az" to "Azərbaycan dili",
        "bg" to "Български",
        "cs" to "Čeština",
        "da" to "Dansk",
        "de" to "Deutsch",
        "el" to "Ελληνικά",
        "es" to "Español",
        "fi" to "Suomi",
        "fr" to "Français",
        "hu" to "Magyar",
        "it" to "Italiano",
        "nb" to "Norsk bokmål",
        "nl" to "Nederlands",
        "pl" to "Polski",
        "pt" to "Português",
        "ro" to "Română",
        "ru" to "Русский",
        "sr" to "Srpski",
        "sv" to "Svenska",
        "uk" to "Українська",
    )
    private val SUPPORTED = NAMES.keys.toList()

    private lateinit var app: Context
    private var cachedLang: String? = null
    private var cachedContext: Context? = null

    fun init(context: Context) {
        app = context.applicationContext
        apply(current())
    }

    /**
     * The app language. On Android 13+ it can also be changed in the system's
     * per-app language settings, and that choice wins. It is read from
     * LocaleManager: AppCompatDelegate.getApplicationLocales() is still empty
     * in Application.onCreate (no Activity yet), so the system choice used to
     * be missed — the screens followed it but the language chip and texts made
     * without an Activity (notifications, services) stayed in the old language.
     */
    fun current(): String {
        val prefs = AppPreferences.getInstance(app)
        if (Build.VERSION.SDK_INT >= 33) {
            val system = app.getSystemService(LocaleManager::class.java)?.applicationLocales
            if (system != null && !system.isEmpty) {
                val lang = normalize(system[0]?.language)
                if (lang != prefs.appLanguage) prefs.appLanguage = lang
                return lang
            }
        }
        return normalize(prefs.appLanguage)
    }

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

    /** Language chooser used on the login, server and settings screens. */
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

    /** "tr-TR" -> "tr"; Norwegian "no"/"nn" -> "nb"; anything unsupported -> English. */
    private fun normalize(lang: String?): String {
        val base = lang?.lowercase()?.substringBefore('-')?.substringBefore('_') ?: return EN
        val code = if (base == "no" || base == "nn") "nb" else base
        return if (code in SUPPORTED) code else EN
    }
}
