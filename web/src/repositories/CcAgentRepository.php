<?php

class CcAgentRepository extends BaseRepository
{
    protected static string $table = 'pbx_queues';

    /**
     * Kullanıcının en az bir aktif kuyruğa temsilci (üye) olarak atanıp
     * atanmadığını kontrol eder (cc_agent olmayan roller için "Temsilci
     * Ekranı"na erişim izni bu kontrolle veriliyor).
     */
    public static function isAssignedQueueAgent(string $extension): bool
    {
        if ($extension === '') {
            return false;
        }
        $all_q_members = static::db()->query("SELECT members_json FROM pbx_queues WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($all_q_members as $m_json) {
            $members = json_decode($m_json ?? '[]', true) ?: [];
            if (in_array($extension, array_map('strval', $members))) {
                return true;
            }
        }
        return false;
    }
}
