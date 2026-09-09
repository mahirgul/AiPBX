<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Web arayüzü dil tercihi (i18n) için sys_users.language_preference.
 * DİKKAT: Bu, bugün sabah tamamlanan Asterisk SESLİ anons dili özelliğinden
 * (pbx_dids/pbx_ivrs/pbx_queues.language) TAMAMEN AYRI bir sistemdir — o,
 * telefon görüşmesindeki sesli anonsların dilini kontrol ediyor; bu,
 * kullanıcının web panelinde gördüğü METİNLERİN dilini kontrol ediyor.
 */
final class AddLanguagePreferenceToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_users')
            ->addColumn('language_preference', 'string', [
                'limit' => 5,
                'default' => 'tr',
                'null' => false,
                'after' => 'theme_preference',
            ])
            ->update();
    }
}
