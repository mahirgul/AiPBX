<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAiKnowledgeTables extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('ai_knowledge_collections')) {
            $this->execute(
                "CREATE TABLE ai_knowledge_collections (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    slug VARCHAR(80) NOT NULL,
                    description VARCHAR(255) NOT NULL DEFAULT '',
                    allowed_roles_json TEXT NULL,
                    embedding_binding VARCHAR(120) NULL,
                    active_index_version INT UNSIGNED NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_ak_col_slug (slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$this->hasTable('ai_knowledge_docs')) {
            $this->execute(
                "CREATE TABLE ai_knowledge_docs (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    collection_id INT UNSIGNED NOT NULL,
                    title VARCHAR(160) NOT NULL,
                    filename VARCHAR(180) NOT NULL,
                    content_text MEDIUMTEXT NOT NULL,
                    sha256 CHAR(64) NOT NULL,
                    version INT UNSIGNED NOT NULL DEFAULT 1,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_ak_doc_col (collection_id, is_active),
                    CONSTRAINT fk_ak_doc_col FOREIGN KEY (collection_id) REFERENCES ai_knowledge_collections(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$this->hasTable('ai_knowledge_chunks')) {
            $this->execute(
                "CREATE TABLE ai_knowledge_chunks (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    collection_id INT UNSIGNED NOT NULL,
                    doc_id INT UNSIGNED NOT NULL,
                    index_version INT UNSIGNED NOT NULL DEFAULT 1,
                    chunk_index INT UNSIGNED NOT NULL,
                    chunk_text TEXT NOT NULL,
                    embedding_json MEDIUMTEXT NULL,
                    model_ref VARCHAR(120) NULL,
                    dimensions INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    KEY idx_akc_col_ver (collection_id, index_version),
                    KEY idx_akc_doc (doc_id),
                    CONSTRAINT fk_akc_col FOREIGN KEY (collection_id) REFERENCES ai_knowledge_collections(id) ON DELETE CASCADE,
                    CONSTRAINT fk_akc_doc FOREIGN KEY (doc_id) REFERENCES ai_knowledge_docs(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        $now = date('Y-m-d H:i:s');
        if ($this->hasTable('sys_plugins')) {
            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('ai-knowledge', 'Knowledge Base (TXT RAG)', '1.0.0', 'official', 1, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE updated_at = '{$now}'"
            );
        }

        if ($this->hasTable('sys_role_permissions')) {
            $this->execute(
                "INSERT IGNORE INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                 VALUES ('admin', 'ai_knowledge', 1, 1, 1, 1)"
            );
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS ai_knowledge_chunks');
        $this->execute('DROP TABLE IF EXISTS ai_knowledge_docs');
        $this->execute('DROP TABLE IF EXISTS ai_knowledge_collections');
        if ($this->hasTable('sys_role_permissions')) {
            $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'ai_knowledge'");
        }
        if ($this->hasTable('sys_plugins')) {
            $this->execute("DELETE FROM sys_plugins WHERE slug = 'ai-knowledge'");
        }
    }
}
