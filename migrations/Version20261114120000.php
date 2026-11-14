<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One curator decides: drops the proposal table of the former two-curator rule (decisions stay in the audit log).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE59472E26B496B');
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE594721844E6B7');
        $this->addSql('ALTER TABLE proposal DROP FOREIGN KEY FK_BFE59472DAB5A938');
        $this->addSql('DROP TABLE proposal');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE proposal (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, profile_fingerprint VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, decided_at DATETIME DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, action VARCHAR(32) NOT NULL, params JSON NOT NULL, reason LONGTEXT NOT NULL, proposed_at DATETIME NOT NULL, decided_by_id INT DEFAULT NULL, server_id INT NOT NULL, proposed_by_id INT DEFAULT NULL, INDEX idx_proposal_status (status, proposed_at), INDEX idx_proposal_server (server_id, status), INDEX IDX_BFE59472E26B496B (decided_by_id), INDEX IDX_BFE594721844E6B7 (server_id), INDEX IDX_BFE59472DAB5A938 (proposed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE59472E26B496B FOREIGN KEY (decided_by_id) REFERENCES curator (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE594721844E6B7 FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposal ADD CONSTRAINT FK_BFE59472DAB5A938 FOREIGN KEY (proposed_by_id) REFERENCES curator (id) ON DELETE SET NULL');
    }
}
