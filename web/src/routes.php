<?php
/**
 * Clean URL → target map (whitelist).
 *
 * 2026-09-01: moved here from index.php. Reason: this table must be the
 * single source of truth for both the front controller and the smoke test
 * (bin/smoke.php) — if the test kept its own route list, a new page would
 * silently fall out of coverage. index.php also dispatches, so it could not
 * be required from outside.
 *
 * Value forms:
 *  - 'role' → role-based home page redirect (handled in index.php)
 *  - array  → ['controller' => X::class, 'action' => 'y', 'module' => 'old_file.php']
 *
 * NOTE: the 'module' key exists to keep auth.php::getModuleKeyForPage()'s
 * basename($_SERVER['PHP_SELF']) mapping — the key that made the MVC
 * migration possible without touching RBAC.
 */
return [
    '/'                => 'role',
    '/index.php'       => 'role',
    '/login'           => ['controller' => LoginController::class, 'action' => 'index', 'module' => 'login.php'],
    '/login-2fa'       => ['controller' => TwoFactorLoginController::class, 'action' => 'index', 'module' => 'login_2fa.php'],
    '/logout'          => ['controller' => LogoutController::class, 'action' => 'index', 'module' => 'logout.php'],
    '/force-reset'     => ['controller' => ForceResetController::class, 'action' => 'index', 'module' => 'force_reset.php'],
    '/reset-password'  => ['controller' => ResetPasswordController::class, 'action' => 'index', 'module' => 'reset_password.php'],
    '/forgot-password' => ['controller' => ForgotPasswordController::class, 'action' => 'index', 'module' => 'forgot_password.php'],
    '/mobile-login'    => ['controller' => MobileLoginController::class, 'action' => 'index', 'module' => 'mobile_login.php'],
    '/security'        => ['controller' => SecurityController::class, 'action' => 'index', 'module' => 'security.php'],
    '/dashboard'       => ['controller' => DashboardController::class, 'action' => 'index', 'module' => 'dashboard.php'],
    '/my-phone'        => ['controller' => MyPhoneController::class, 'action' => 'index', 'module' => 'my_phone.php'],
    '/chat'            => ['controller' => ChatController::class, 'action' => 'index', 'module' => 'chat.php'],
    '/trunks'          => ['controller' => TrunkController::class, 'action' => 'index', 'module' => 'trunks.php'],
    '/did-routes'      => ['controller' => DidRouteController::class, 'action' => 'index', 'module' => 'did_routes.php'],
    '/outbound-routes' => ['controller' => OutboundRouteController::class, 'action' => 'index', 'module' => 'outbound_routes.php'],
    '/dial-permissions' => ['controller' => DialPermissionController::class, 'action' => 'index', 'module' => 'dial_permissions.php'],
    '/time-conditions' => ['controller' => TimeConditionController::class, 'action' => 'index', 'module' => 'time_conditions.php'],
    '/ivrs'            => ['controller' => IvrController::class, 'action' => 'index', 'module' => 'ivrs.php'],
    '/extensions'      => ['controller' => ExtensionController::class, 'action' => 'index', 'module' => 'extensions.php'],
    '/phones'          => ['controller' => PhoneController::class, 'action' => 'index', 'module' => 'phones.php'],
    '/phone-keys'      => ['controller' => PhoneKeysController::class, 'action' => 'index', 'module' => 'phone_keys.php'],
    '/ring-groups'      => ['controller' => RingGroupController::class, 'action' => 'index', 'module' => 'ring_groups.php'],
    '/conferences'      => ['controller' => ConferenceController::class, 'action' => 'index', 'module' => 'conferences.php'],
    '/boss-secretary'   => ['controller' => BossSecretaryController::class, 'action' => 'index', 'module' => 'boss_secretary.php'],
    '/queues'          => ['controller' => QueueController::class, 'action' => 'index', 'module' => 'queues.php'],
    '/sounds'          => ['controller' => SoundController::class, 'action' => 'index', 'module' => 'sounds.php'],
    '/end-call'        => ['controller' => HangupActionController::class, 'action' => 'index', 'module' => 'end_call.php'],
    '/feature-codes'        => ['controller' => FeatureCodeController::class, 'action' => 'index', 'module' => 'feature_codes.php'],
    '/feature-codes-status' => ['controller' => FeatureCodeStatusController::class, 'action' => 'index', 'module' => 'feature_codes_status.php'],
    '/system-users'    => ['controller' => SystemUserController::class, 'action' => 'index', 'module' => 'system_users.php'],
    '/roles'           => ['controller' => RoleController::class, 'action' => 'index', 'module' => 'roles.php'],
    '/asterisk-settings' => ['controller' => AsteriskSettingsController::class, 'action' => 'index', 'module' => 'asterisk_settings.php'],
    '/pending-sync'    => ['controller' => PendingSyncController::class, 'action' => 'index', 'module' => 'pending_sync.php'],
    '/audit-log'       => ['controller' => AuditLogController::class, 'action' => 'index', 'module' => 'audit_log.php'],
    '/firewall'        => ['controller' => FirewallController::class, 'action' => 'index', 'module' => 'firewall.php'],
    '/network-services' => ['controller' => NetworkServicesController::class, 'action' => 'index', 'module' => 'network_services.php'],
    '/fail2ban'        => ['controller' => Fail2banController::class, 'action' => 'index', 'module' => 'fail2ban.php'],
    '/certificates'    => ['controller' => CertificateController::class, 'action' => 'index', 'module' => 'certificates.php'],
    '/system-update'   => ['controller' => SystemUpdateController::class, 'action' => 'index', 'module' => 'system_update.php'],
    '/brand-settings'  => ['controller' => BrandSettingsController::class, 'action' => 'index', 'module' => 'brand_settings.php'],
    '/push-settings'   => ['controller' => PushSettingsController::class, 'action' => 'index', 'module' => 'push_settings.php'],
    '/fax-settings'    => ['controller' => FaxSettingsController::class, 'action' => 'index', 'module' => 'fax_settings.php'],
    '/fax-mail-settings' => ['controller' => FaxMailSettingsController::class, 'action' => 'index', 'module' => 'fax_mail_settings.php'],
    '/mail-settings'   => ['controller' => MailSettingsController::class, 'action' => 'index', 'module' => 'mail_settings.php'],
    '/mail-templates'  => ['controller' => MailTemplatesController::class, 'action' => 'index', 'module' => 'mail_settings.php'],
    '/file-storage'    => ['controller' => FileStorageController::class, 'action' => 'index', 'module' => 'file_storage.php'],
    '/cdr-reports'     => ['controller' => CdrReportController::class, 'action' => 'index', 'module' => 'cdr_reports.php'],
    '/queue-reports'   => ['controller' => QueueReportController::class, 'action' => 'index', 'module' => 'queue_reports.php'],
    '/pause-reports'   => ['controller' => PauseReportController::class, 'action' => 'index', 'module' => 'pause_reports.php'],
    '/queue-logs'      => ['controller' => QueueLogController::class, 'action' => 'index', 'module' => 'queue_logs.php'],
    '/cc-agent'        => ['controller' => CcAgentController::class, 'action' => 'index', 'module' => 'cc_agent.php'],
    '/cc-supervisor'   => ['controller' => CcSupervisorController::class, 'action' => 'index', 'module' => 'cc_supervisor.php'],
    '/cc-board'        => ['controller' => CcBoardController::class, 'action' => 'index', 'module' => 'cc_board.php'],
    '/fax-inbox'       => ['controller' => FaxInboxController::class, 'action' => 'index', 'module' => 'fax_inbox.php'],
    '/fax-send'        => ['controller' => FaxSendController::class, 'action' => 'index', 'module' => 'fax_send.php'],
    '/fax-sent'        => ['controller' => FaxSentController::class, 'action' => 'index', 'module' => 'fax_sent.php'],
    '/ai-tts'          => ['controller' => AiTtsController::class, 'action' => 'index', 'module' => 'ai_tts.php'],
    '/ai-models'       => ['controller' => AiModelsController::class, 'action' => 'index', 'module' => 'ai_models.php'],
    '/ai-cloud'        => ['controller' => AiCloudController::class, 'action' => 'index', 'module' => 'ai_cloud.php'],
    '/ms-teams'        => ['controller' => MsTeamsController::class, 'action' => 'index', 'module' => 'ms_teams.php'],
    '/web-widgets'     => ['controller' => WebWidgetController::class, 'action' => 'index', 'module' => 'web_widgets.php'],
    '/google-integration' => ['controller' => GoogleIntegrationController::class, 'action' => 'index', 'module' => 'google_integration.php'],
    '/integrations/google' => ['controller' => GoogleIntegrationController::class, 'action' => 'index', 'module' => 'google_integration.php'],
    '/auth/google'     => ['controller' => GoogleAuthController::class, 'action' => 'auth', 'module' => 'google_auth.php'],
    '/auth/google/callback' => ['controller' => GoogleAuthController::class, 'action' => 'callback', 'module' => 'google_auth_callback.php'],
];

