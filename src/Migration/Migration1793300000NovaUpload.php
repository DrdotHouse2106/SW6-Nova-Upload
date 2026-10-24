<?php declare(strict_types=1);

namespace Nova\Upload\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Hochgeladene Dateien (Kontaktformular und Artikel). Die Dateien selbst liegen im privaten
 * Dateisystem des Plugins (files/plugins/nova_upload) – nicht über eine URL erreichbar.
 */
class Migration1793300000NovaUpload extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793300000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nova_upload` (
                `id` BINARY(16) NOT NULL,
                `source` VARCHAR(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `path` VARCHAR(255) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(255) NOT NULL,
                `size` INT UNSIGNED NOT NULL,
                `customer_name` VARCHAR(255) NULL,
                `customer_email` VARCHAR(255) NULL,
                `reference` VARCHAR(255) NULL,
                `product_id` BINARY(16) NULL,
                `line_item_id` VARCHAR(64) NULL,
                `order_id` BINARY(16) NULL,
                `order_number` VARCHAR(64) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `assigned_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.nova_upload.created` (`created_at`),
                KEY `idx.nova_upload.order` (`order_id`),
                KEY `idx.nova_upload.line_item` (`line_item_id`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
