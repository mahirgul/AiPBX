<?php
require_once __DIR__ . '/../services/MailTemplateService.php';

/** Admin → E-Mail → Templates: edit, preview and test the e-mail templates. */
class MailTemplatesController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (static::isPost() && isset($_POST['ajax'])) {
            self::ajax();
        }

        $notices = static::handlePost([
            'save_default_language' => function () {
                if (!static::verifyCsrf()) {
                    return ['success' => false, 'error' => t('common.invalid_csrf')];
                }
                $lang = (string) ($_POST['mail_default_language'] ?? '');
                if (!isset(UI_LANGUAGES[$lang])) {
                    return ['success' => false, 'error' => t('mail_templates.err_unknown')];
                }
                getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                    ->execute(['mail_default_language', $lang]);
                return ['success' => true, 'message' => t('mail_templates.default_language_saved')];
            },
        ]);

        $key = (string) ($_GET['t'] ?? 'invite_new');
        if (!MailTemplateService::exists($key)) {
            $key = 'invite_new';
        }
        $lang = (string) ($_GET['lang'] ?? getUserLanguage());
        if (!isset(UI_LANGUAGES[$lang])) {
            $lang = getUserLanguage();
        }

        $me = getCurrentUser();
        static::renderPage('mail_templates/index', [
            'templateKey' => $key,
            'lang' => $lang,
            'template' => ['body' => MailTemplateService::pretty(MailTemplateService::get($key, $lang)['body'])] + MailTemplateService::get($key, $lang),
            'customized' => MailTemplateService::customizedLanguages(),
            'variables' => MailTemplateService::TEMPLATES[$key],
            'systemLanguage' => MailTemplateService::systemLanguage(),
            'myEmail' => (string) ($me['email'] ?? ''),
        ], ['title' => t('mail_templates.title')] + $notices);
    }

    /** In-page actions: preview, save, reset, test (JSON). */
    private static function ajax(): never
    {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            static::json(['success' => false, 'error' => t('common.invalid_csrf')]);
        }
        $key = (string) ($_POST['template'] ?? '');
        $lang = (string) ($_POST['lang'] ?? '');
        if (!MailTemplateService::exists($key) || !isset(UI_LANGUAGES[$lang])) {
            static::json(['success' => false, 'error' => t('mail_templates.err_unknown')]);
        }
        $subject = (string) ($_POST['subject'] ?? '');
        $body = (string) ($_POST['body'] ?? '');

        switch ($_POST['ajax']) {
            case 'preview':
                $sample = MailTemplateService::sampleData($key, $lang);
                $mail = MailTemplateService::render($key, $lang, $sample['vars'], $sample['blocks'], ['subject' => $subject, 'body' => $body]);
                // The logo is an inline attachment in a real mail; the preview shows it from the portal.
                $logo = MailTemplateService::brand()['logo'];
                if ($logo) {
                    $url = str_replace(realpath(dirname(__DIR__, 2)), '', $logo);
                    $mail['html'] = str_replace('cid:aipbx-logo', htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $mail['html']);
                }
                static::json(['success' => true, 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
            case 'save':
                $res = MailTemplateService::save($key, $lang, $subject, $body);
                if (!empty($res['body'])) {
                    $res['body'] = MailTemplateService::pretty($res['body']);
                }
                static::json($res);
            case 'reset':
                $res = MailTemplateService::reset($key, $lang);
                $res['body'] = MailTemplateService::pretty($res['body']);
                static::json($res);
            case 'test':
                $to = trim((string) ($_POST['to'] ?? ''));
                $sample = MailTemplateService::sampleData($key, $lang);
                $mail = MailTemplateService::render($key, $lang, $sample['vars'], $sample['blocks'], ['subject' => $subject, 'body' => $body]);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    static::json(['success' => false, 'error' => t('srv_mail.err_to')]);
                }
                [$from, $fromName] = MailTemplateService::sender(str_starts_with($key, 'fax_') ? 'fax' : 'portal');
                [$headers, $mime] = MailTemplateService::buildMime($mail, $from, $fromName);
                $subjectHeader = '=?UTF-8?B?' . base64_encode('[TEST] ' . $mail['subject']) . '?=';
                $ok = @mail($to, $subjectHeader, $mime, $headers, '-f ' . $from);
                static::json($ok
                    ? ['success' => true, 'message' => sprintf(t('mail_templates.test_sent'), $to)]
                    : ['success' => false, 'error' => sprintf(t('srv_mail.err_send'), error_get_last()['message'] ?? t('srv_mail.err_unknown'))]);
        }
        static::json(['success' => false, 'error' => t('mail_templates.err_unknown')]);
    }
}
