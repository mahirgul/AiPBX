<?php
/**
 * Pre-render Action Handler for `/plugins/auto-dialer`.
 * Handles CSV export downloads and campaign management POST actions.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $slug, array $settings): array {
    $isTr = (function_exists('getUserLanguage') && getUserLanguage() === 'tr');

    // CSV Export download (`GET /plugins/auto-dialer?export_csv=<campaign_id>`)
    if (!empty($_GET['export_csv'])) {
        $campId = (int)$_GET['export_csv'];
        $camp = AutoDialerService::getCampaign($campId);
        if ($camp) {
            if (function_exists('session_write_close')) {
                @session_write_close();
            }
            $rows = AutoDialerService::listNumbers($campId, 1000);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="autodial_campaign_' . $campId . '_results.csv"');
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['ID', 'Phone Number', 'Contact Name', 'Notes', 'Status', 'Attempts', 'Call Result', 'AMD Status', 'AMD Cause', 'Duration (s)', 'Last Attempt']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['id'],
                        $r['phone_number'],
                        $r['contact_name'],
                        $r['notes'],
                        $r['status'],
                        $r['attempts'],
                        $r['call_result'],
                        $r['amd_status'],
                        $r['amd_cause'],
                        $r['duration'],
                        $r['last_attempt_at'] ?? '',
                    ]);
                }
                fclose($out);
            }
            exit;
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf')];
    }

    $action = (string)($_POST['autodial_action'] ?? '');
    if ($action === '') {
        return [];
    }

    try {
        if ($action === 'create_campaign') {
            $id = AutoDialerService::createCampaign($_POST);
            return [
                'message' => $isTr
                    ? "Kampanya (#{$id}) oluşturuldu ve Asterisk dialplan güncellendi."
                    : "Campaign (#{$id}) created and Asterisk dialplan synced.",
            ];
        }

        if ($action === 'set_status') {
            $campId = (int)($_POST['campaign_id'] ?? 0);
            $newStatus = (string)($_POST['new_status'] ?? 'paused');
            AutoDialerService::setCampaignStatus($campId, $newStatus);
            if ($newStatus === 'running') {
                AutoDialerService::tickCampaigns($settings);
            }
            return [
                'message' => $isTr
                    ? "Kampanya durumu güncellendi: {$newStatus}"
                    : "Campaign status updated to: {$newStatus}",
            ];
        }

        if ($action === 'add_numbers') {
            $campId = (int)($_POST['campaign_id'] ?? 0);
            $text = (string)($_POST['numbers_bulk'] ?? '');
            if (!empty($_FILES['numbers_csv']['tmp_name']) && is_uploaded_file($_FILES['numbers_csv']['tmp_name'])) {
                $text .= "\n" . (string)file_get_contents($_FILES['numbers_csv']['tmp_name']);
            }
            $added = AutoDialerService::importNumbersFromText($campId, $text);
            return [
                'message' => $isTr
                    ? "{$added} numara kampanyaya eklendi."
                    : "{$added} phone numbers added to the campaign.",
            ];
        }

        if ($action === 'tick_now') {
            $originated = AutoDialerService::tickCampaigns($settings);
            return [
                'message' => $isTr
                    ? "Arama döngüsü çalıştırıldı ({$originated} yeni çağrı başlatıldı)."
                    : "Dialer cycle executed ({$originated} new calls originated).",
            ];
        }

        if ($action === 'reset_numbers') {
            $campId = (int)($_POST['campaign_id'] ?? 0);
            $onlyFailed = !empty($_POST['only_failed']);
            $cnt = AutoDialerService::resetCampaignNumbers($campId, $onlyFailed);
            return [
                'message' => $isTr
                    ? "{$cnt} numara tekrar aranmak üzere sıfırlandı."
                    : "{$cnt} numbers reset to pending.",
            ];
        }

        if ($action === 'delete_campaign') {
            $campId = (int)($_POST['campaign_id'] ?? 0);
            AutoDialerService::deleteCampaign($campId);
            return [
                'message' => $isTr
                    ? "Kampanya (#{$campId}) ve bağlı numaraları silindi."
                    : "Campaign (#{$campId}) and its numbers deleted.",
            ];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    return [];
};
