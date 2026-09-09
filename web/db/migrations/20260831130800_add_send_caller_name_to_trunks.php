<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dış Hat Ayarları'na "Giden Aramalarda Görünen Ad Gönder" seçeneği
 * (2026-08-31, kullanıcı isteği) — şu an tek tüketicisi faks gönderimi
 * (FaxSendService::submitCallFile()): kapalıysa Caller ID sadece numara,
 * açıksa "{birim adı}" <{numara}> olarak gönderilir. Varsayılan kapalı
 * (0) — aynı oturumda "SIP tarafında from kısmına isim koymayalım"
 * denip faks için isimsiz gönderime BİLEREK geçilmişti, bu varsayılan
 * o kararla tutarlı; admin isterse trunk bazında açabilir.
 */
final class AddSendCallerNameToTrunks extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('send_caller_name', 'boolean', [
                'default' => false,
                'after' => 'qualify_frequency',
            ])
            ->update();
    }
}
