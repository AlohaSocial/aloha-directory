<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113200616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema of the Aloha Directory (MariaDB 10.11+), plus the session, cache, lock and queue tables of Symfony.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_head (day VARCHAR(10) NOT NULL, seq BIGINT NOT NULL, hash VARCHAR(64) NOT NULL, issued_at DATETIME NOT NULL, envelope LONGTEXT NOT NULL, PRIMARY KEY (day)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id BIGINT AUTO_INCREMENT NOT NULL, chain_seq BIGINT DEFAULT NULL, chain_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_id INT DEFAULT NULL, actor_label VARCHAR(254) NOT NULL, action VARCHAR(64) NOT NULL, server_domain VARCHAR(253) DEFAULT NULL, details JSON NOT NULL, INDEX idx_audit_time (created_at), INDEX idx_audit_server (server_domain, created_at), INDEX idx_audit_action (action, created_at), UNIQUE INDEX uniq_audit_chain_seq (chain_seq), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blocked_domain (id INT AUTO_INCREMENT NOT NULL, domain VARCHAR(253) NOT NULL, source VARCHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_blocked_domain (domain), UNIQUE INDEX uniq_blocked_domain (domain, source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, from_server TINYINT NOT NULL, visibility VARCHAR(16) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, server_id INT NOT NULL, curator_id INT DEFAULT NULL, complaint_id INT DEFAULT NULL, INDEX idx_comment_server (server_id, created_at), INDEX IDX_9474526C1844E6B7 (server_id), INDEX IDX_9474526C733D5B5D (curator_id), INDEX IDX_9474526CEDAE188E (complaint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE complaint (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, shared_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, resolution LONGTEXT DEFAULT NULL, category VARCHAR(32) NOT NULL, body LONGTEXT NOT NULL, reporter_email VARCHAR(254) DEFAULT NULL, created_at DATETIME NOT NULL, closed_by_id INT DEFAULT NULL, server_id INT NOT NULL, INDEX idx_complaint_status (status, created_at), INDEX idx_complaint_server (server_id, status), INDEX IDX_5F2732B5E1FA7797 (closed_by_id), INDEX IDX_5F2732B51844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE curator (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, active TINYINT NOT NULL, webauthn_user_handle VARCHAR(64) DEFAULT NULL, recovery_codes JSON NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, password_changed_at DATETIME DEFAULT NULL, credentials_version INT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_73C39149D13D977A (webauthn_user_handle), UNIQUE INDEX uniq_curator_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE curator_conflict (id INT AUTO_INCREMENT NOT NULL, note VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, curator_id INT NOT NULL, server_id INT NOT NULL, UNIQUE INDEX uniq_conflict (curator_id, server_id), INDEX IDX_82E22385733D5B5D (curator_id), INDEX IDX_82E223851844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE curator_invite (id INT AUTO_INCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(100) NOT NULL, admin TINYINT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, invited_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_invite_token (token_hash), INDEX IDX_EE479F61A7B4A7E3 (invited_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE curator_passkey (id INT AUTO_INCREMENT NOT NULL, last_used_at DATETIME DEFAULT NULL, credential_id VARCHAR(255) NOT NULL, record LONGTEXT NOT NULL, name VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, curator_id INT NOT NULL, UNIQUE INDEX uniq_passkey_credential (credential_id), INDEX IDX_22AE874A733D5B5D (curator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE heartbeat (id BIGINT AUTO_INCREMENT NOT NULL, received_at DATETIME NOT NULL, sent_at DATETIME NOT NULL, signup_open TINYINT NOT NULL, available_accounts INT NOT NULL, approval_backlog INT NOT NULL, users_total INT DEFAULT NULL, users_active_month INT DEFAULT NULL, software_version VARCHAR(32) NOT NULL, federation_ok TINYINT NOT NULL, queue_lag_seconds INT DEFAULT NULL, search_load DOUBLE PRECISION DEFAULT NULL, server_id INT NOT NULL, INDEX idx_heartbeat_server_time (server_id, received_at), INDEX idx_heartbeat_time (received_at), INDEX IDX_A7DEDD91844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE heartbeat_daily (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, heartbeats INT NOT NULL, min_available_accounts INT NOT NULL, max_approval_backlog INT NOT NULL, max_users_active_month INT DEFAULT NULL, federation_failures INT NOT NULL, server_id INT NOT NULL, UNIQUE INDEX uniq_heartbeat_daily (server_id, day), INDEX IDX_7512F4E81844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE list_build (id INT AUTO_INCREMENT NOT NULL, list VARCHAR(16) NOT NULL, serial BIGINT NOT NULL, issued_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, envelope_sha256 VARCHAR(64) NOT NULL, entries_hash VARCHAR(64) NOT NULL, entry_count INT NOT NULL, payload LONGTEXT NOT NULL, envelope LONGTEXT NOT NULL, key_ids JSON NOT NULL, reason VARCHAR(32) NOT NULL, INDEX idx_list_build_time (list, issued_at), UNIQUE INDEX uniq_list_serial (list, serial), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE list_state (serial BIGINT NOT NULL, last_built_at DATETIME DEFAULT NULL, entries_hash VARCHAR(64) DEFAULT NULL, change_pending_since DATETIME DEFAULT NULL, force_rebuild TINYINT DEFAULT 0 NOT NULL, held_since DATETIME DEFAULT NULL, held_hash VARCHAR(64) DEFAULT NULL, held_summary JSON DEFAULT NULL, released_hash VARCHAR(64) DEFAULT NULL, name VARCHAR(16) NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pending_enrolment (id INT AUTO_INCREMENT NOT NULL, verify_status VARCHAR(16) DEFAULT NULL, verify_requested_at DATETIME DEFAULT NULL, verify_finished_at DATETIME DEFAULT NULL, verify_error VARCHAR(64) DEFAULT NULL, verify_message VARCHAR(500) DEFAULT NULL, domain VARCHAR(253) NOT NULL, public_key VARCHAR(64) NOT NULL, key_id VARCHAR(64) NOT NULL, challenge_hash VARCHAR(64) NOT NULL, contact_email VARCHAR(254) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_pending_domain_key (domain, key_id), INDEX idx_pending_expiry (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE proposal (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, profile_fingerprint VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, decided_at DATETIME DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, action VARCHAR(32) NOT NULL, params JSON NOT NULL, reason LONGTEXT NOT NULL, proposed_at DATETIME NOT NULL, decided_by_id INT DEFAULT NULL, server_id INT NOT NULL, proposed_by_id INT DEFAULT NULL, INDEX idx_proposal_status (status, proposed_at), INDEX idx_proposal_server (server_id, status), INDEX IDX_BFE59472E26B496B (decided_by_id), INDEX IDX_BFE594721844E6B7 (server_id), INDEX IDX_BFE59472DAB5A938 (proposed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, profile_fingerprint VARCHAR(64) DEFAULT NULL, kind VARCHAR(16) NOT NULL, checklist JSON NOT NULL, notes LONGTEXT NOT NULL, created_at DATETIME NOT NULL, server_id INT NOT NULL, curator_id INT DEFAULT NULL, INDEX idx_review_server (server_id, created_at), INDEX IDX_794381C61844E6B7 (server_id), INDEX IDX_794381C6733D5B5D (curator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE server (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, domain VARCHAR(253) NOT NULL, state VARCHAR(32) NOT NULL, featured TINYINT DEFAULT 0 NOT NULL, search_provider TINYINT DEFAULT 0 NOT NULL, weight_percent SMALLINT DEFAULT 100 NOT NULL, balance_percent SMALLINT DEFAULT 100 NOT NULL, new_accounts7d INT DEFAULT NULL, public_key VARCHAR(64) DEFAULT NULL, key_id VARCHAR(64) DEFAULT NULL, admin_email VARCHAR(254) DEFAULT NULL, enrolled_at DATETIME NOT NULL, verified_at DATETIME DEFAULT NULL, applied_at DATETIME DEFAULT NULL, in_review_at DATETIME DEFAULT NULL, listed_at DATETIME DEFAULT NULL, featured_at DATETIME DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, next_review_at DATETIME DEFAULT NULL, pause_kind VARCHAR(16) DEFAULT NULL, paused_at DATETIME DEFAULT NULL, pause_reason LONGTEXT DEFAULT NULL, delisted_at DATETIME DEFAULT NULL, withdrawn_at DATETIME DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, decision_reason LONGTEXT DEFAULT NULL, profile JSON DEFAULT NULL, approved_profile JSON DEFAULT NULL, pending_profile JSON DEFAULT NULL, profile_etag VARCHAR(128) DEFAULT NULL, profile_requested TINYINT DEFAULT 0 NOT NULL, profile_updated_at DATETIME DEFAULT NULL, last_heartbeat_at DATETIME DEFAULT NULL, signup_open TINYINT DEFAULT NULL, signup_mode VARCHAR(16) DEFAULT NULL, available_accounts INT DEFAULT NULL, approval_backlog INT DEFAULT NULL, software_version VARCHAR(32) DEFAULT NULL, nextcloud_version VARCHAR(32) DEFAULT NULL, users_total INT DEFAULT NULL, users_active_month INT DEFAULT NULL, heartbeat_search JSON DEFAULT NULL, search_load DOUBLE PRECISION DEFAULT NULL, providers_used INT DEFAULT NULL, federation_ok TINYINT DEFAULT NULL, federation_failing_since DATETIME DEFAULT NULL, queue_lag_seconds INT DEFAULT NULL, heartbeat_atproto JSON DEFAULT NULL, below_min_version_since DATETIME DEFAULT NULL, abuse_test_mail_sent_to VARCHAR(254) DEFAULT NULL, abuse_test_mail_sent_at DATETIME DEFAULT NULL, atproto_verified TINYINT DEFAULT NULL, logo_hash VARCHAR(64) DEFAULT NULL, banner_hash VARCHAR(64) DEFAULT NULL, media_pending JSON DEFAULT NULL, media_error LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX idx_server_state (state), INDEX idx_server_key (key_id), INDEX idx_server_review (next_review_at), UNIQUE INDEX uniq_server_domain (domain), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE server_check (id INT AUTO_INCREMENT NOT NULL, passed TINYINT NOT NULL, message LONGTEXT NOT NULL, checked_at DATETIME NOT NULL, failing_since DATETIME DEFAULT NULL, consecutive_failures INT DEFAULT 0 NOT NULL, name VARCHAR(32) NOT NULL, server_id INT NOT NULL, UNIQUE INDEX uniq_server_check (server_id, name), INDEX IDX_9C0FCE1A1844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE server_check_daily (id INT AUTO_INCREMENT NOT NULL, passes INT DEFAULT 0 NOT NULL, failures INT DEFAULT 0 NOT NULL, day VARCHAR(10) NOT NULL, name VARCHAR(32) NOT NULL, server_id INT NOT NULL, INDEX idx_check_daily_day (day), UNIQUE INDEX uniq_check_daily (server_id, day, name), INDEX IDX_5CA761731844E6B7 (server_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signature_nonce (hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_nonce_expiry (expires_at), PRIMARY KEY (hash)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE system_state (value LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(64) NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transparency_entry (id INT AUTO_INCREMENT NOT NULL, appeal_outcome LONGTEXT DEFAULT NULL, server_domain VARCHAR(253) NOT NULL, kind VARCHAR(32) NOT NULL, rule VARCHAR(64) NOT NULL, reason LONGTEXT NOT NULL, decided_at DATETIME NOT NULL, INDEX idx_transparency_time (decided_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transparency_report (id INT AUTO_INCREMENT NOT NULL, quarter VARCHAR(7) NOT NULL, generated_at DATETIME NOT NULL, data JSON NOT NULL, UNIQUE INDEX uniq_report_quarter (quarter), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C1844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C733D5B5D FOREIGN KEY (curator_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CEDAE188E FOREIGN KEY (complaint_id) REFERENCES complaint (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_5F2732B5E1FA7797 FOREIGN KEY (closed_by_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE complaint ADD CONSTRAINT FK_5F2732B51844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curator_conflict ADD CONSTRAINT FK_82E22385733D5B5D FOREIGN KEY (curator_id) REFERENCES curator (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curator_conflict ADD CONSTRAINT FK_82E223851844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curator_invite ADD CONSTRAINT FK_EE479F61A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE curator_passkey ADD CONSTRAINT FK_22AE874A733D5B5D FOREIGN KEY (curator_id) REFERENCES curator (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE heartbeat ADD CONSTRAINT FK_A7DEDD91844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE heartbeat_daily ADD CONSTRAINT FK_7512F4E81844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE59472E26B496B FOREIGN KEY (decided_by_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE594721844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE59472DAB5A938 FOREIGN KEY (proposed_by_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C61844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6733D5B5D FOREIGN KEY (curator_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE server_check ADD CONSTRAINT FK_9C0FCE1A1844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE server_check_daily ADD CONSTRAINT FK_5CA761731844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        // Symfony component tables: curator sessions shared by all hosts, rate limiter state, cron locks.
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL, sess_data LONGBLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C1844E6B7');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C733D5B5D');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CEDAE188E');
        $this->addSql('ALTER TABLE complaint DROP FOREIGN KEY FK_5F2732B5E1FA7797');
        $this->addSql('ALTER TABLE complaint DROP FOREIGN KEY FK_5F2732B51844E6B7');
        $this->addSql('ALTER TABLE curator_conflict DROP FOREIGN KEY FK_82E22385733D5B5D');
        $this->addSql('ALTER TABLE curator_conflict DROP FOREIGN KEY FK_82E223851844E6B7');
        $this->addSql('ALTER TABLE curator_invite DROP FOREIGN KEY FK_EE479F61A7B4A7E3');
        $this->addSql('ALTER TABLE curator_passkey DROP FOREIGN KEY FK_22AE874A733D5B5D');
        $this->addSql('ALTER TABLE heartbeat DROP FOREIGN KEY FK_A7DEDD91844E6B7');
        $this->addSql('ALTER TABLE heartbeat_daily DROP FOREIGN KEY FK_7512F4E81844E6B7');
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE59472E26B496B');
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE594721844E6B7');
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE59472DAB5A938');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C61844E6B7');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6733D5B5D');
        $this->addSql('ALTER TABLE server_check DROP FOREIGN KEY FK_9C0FCE1A1844E6B7');
        $this->addSql('ALTER TABLE server_check_daily DROP FOREIGN KEY FK_5CA761731844E6B7');
        $this->addSql('DROP TABLE audit_head');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE blocked_domain');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE complaint');
        $this->addSql('DROP TABLE curator');
        $this->addSql('DROP TABLE curator_conflict');
        $this->addSql('DROP TABLE curator_invite');
        $this->addSql('DROP TABLE curator_passkey');
        $this->addSql('DROP TABLE heartbeat');
        $this->addSql('DROP TABLE heartbeat_daily');
        $this->addSql('DROP TABLE list_build');
        $this->addSql('DROP TABLE list_state');
        $this->addSql('DROP TABLE pending_enrolment');
        $this->addSql('DROP TABLE proposal');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE server');
        $this->addSql('DROP TABLE server_check');
        $this->addSql('DROP TABLE server_check_daily');
        $this->addSql('DROP TABLE signature_nonce');
        $this->addSql('DROP TABLE system_state');
        $this->addSql('DROP TABLE transparency_entry');
        $this->addSql('DROP TABLE transparency_report');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('DROP TABLE lock_keys');
    }
}
