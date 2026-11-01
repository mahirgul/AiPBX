<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * IDs of SAML assertions that already signed someone in, so that a captured response cannot be replayed.
 */
final class CreateSamlUsedAssertions extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('sys_saml_used_assertions')) {
            $this->table('sys_saml_used_assertions', ['id' => false, 'primary_key' => ['assertion_hash']])
                ->addColumn('assertion_hash', 'char', ['limit' => 64])
                ->addColumn('expires_at', 'datetime')
                ->addIndex(['expires_at'])
                ->create();
        }
    }
}
