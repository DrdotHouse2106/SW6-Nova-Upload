<?php declare(strict_types=1);

namespace Nova\Upload\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Täglich: abgelaufene Uploads löschen (Fristen in der Plugin-Konfiguration).
 */
class UploadCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_upload.cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
