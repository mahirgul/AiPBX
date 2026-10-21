package com.mhrgl.aipbx.ui

/**
 * Intent extras of a chat conversation and the conversation open on screen.
 * The chat itself lives in [DialerActivity] (chat tab); notifications and the
 * service use these to open it and to suppress notifications for the open one.
 */
object ChatKeys {
    const val EXTRA_CONV_ID = "extra_conv_id"
    const val EXTRA_TARGET_EXT = "extra_target_ext"
    const val EXTRA_TARGET_NAME = "extra_target_name"
    const val EXTRA_IS_GROUP = "extra_is_group"

    /** Conversation currently open in the chat tab (0 = none). */
    @Volatile
    var activeConversationId: Int = 0
}
