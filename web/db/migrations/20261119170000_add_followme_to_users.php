<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Follow Me of an extension (DialplanExtensionBuilder): up to five numbers rung after the
 * extension (sequential) or with it (parallel); an outside number is accepted with "press 1".
 */
final class AddFollowmeToUsers extends AbstractMigration
{
    public function up(): void
    {
        $t = $this->table('sys_users');
        $cols = [
            'followme_numbers' => ['string', ['limit' => 255, 'null' => true]],
            'followme_mode' => ['string', ['limit' => 10, 'default' => 'sequential', 'null' => false]],
            'followme_ring_time' => ['integer', ['default' => 20, 'null' => false]],
            'followme_confirm' => ['boolean', ['default' => 1, 'null' => false]],
        ];
        foreach ($cols as $name => [$type, $opts]) {
            if (!$t->hasColumn($name)) {
                $t->addColumn($name, $type, $opts);
            }
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('sys_users');
        foreach (['followme_numbers', 'followme_mode', 'followme_ring_time', 'followme_confirm'] as $c) {
            if ($t->hasColumn($c)) {
                $t->removeColumn($c);
            }
        }
        $t->update();
    }
}
