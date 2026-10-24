<?php declare(strict_types=1);

namespace Nova\Upload\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Freiwillige Zustimmung des Kunden: Teil aus der Anfrage darf ins Sortiment aufgenommen werden.
 */
class Migration1793400000NovaUploadShareConsent extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793400000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn("SHOW COLUMNS FROM `nova_upload` LIKE 'share_consent'");
        if ($columns === []) {
            $connection->executeStatement('ALTER TABLE `nova_upload` ADD COLUMN `share_consent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reference`');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
