<?php declare(strict_types=1);

namespace Nova\Upload\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kontingent für Artikel-Uploads (ohne Anmeldung möglich): Dateien und Megabyte je Besucher (IP)
 * und Stunde sowie Gesamtgröße aller noch keiner Bestellung zugeordneten Uploads – schützt den
 * Speicherplatz.
 *
 * Die Gesamtgrenze ist selbst ein Hebel (wer sie füllt, sperrt Artikel-Uploads für alle). Deshalb:
 * kleineres Kontingent je IP (auch in MB, nicht nur Dateien), beim Erreichen der Grenze zuerst
 * alte offene Uploads vorzeitig löschen und die Administration benachrichtigen.
 */
class UploadQuota
{
    private const WINDOW = 3600;

    /** Offene Uploads, die älter sind, werden bei voller Gesamtgrenze vorzeitig gelöscht */
    private const EMERGENCY_CLEANUP_HOURS = 72;

    /** Höchstens eine Benachrichtigung in der Administration je Zeitraum */
    private const NOTIFY_INTERVAL = 6 * 3600;

    private const MB = 1024 * 1024;

    /**
     * @param EntityRepository<EntityCollection<Entity>>|null $notificationRepository Benachrichtigungen (notification.repository)
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly UploadStore $store,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?EntityRepository $notificationRepository = null,
    ) {
    }

    public function acceptProductUploads(Request $request, int $files, ?string $salesChannelId, int $bytes = 0): bool
    {
        if (!$this->hasFreeTotal($bytes, $salesChannelId)) {
            return false;
        }

        $filesPerHour = $this->int('productUploadsPerHour', $salesChannelId, 20);
        $mbPerHour = $this->int('productUploadMbPerHour', $salesChannelId, 200);
        if ($filesPerHour <= 0 && $mbPerHour <= 0) {
            return true;
        }

        $window = intdiv(time(), self::WINDOW);
        $visitor = hash('sha256', (string) $request->getClientIp());

        try {
            $filesItem = $this->cache->getItem('nova_upload_quota_' . $visitor . '_' . $window);
            $bytesItem = $this->cache->getItem('nova_upload_quota_bytes_' . $visitor . '_' . $window);
            $fileCount = $filesItem->isHit() && \is_int($filesItem->get()) ? $filesItem->get() : 0;
            $byteCount = $bytesItem->isHit() && \is_int($bytesItem->get()) ? $bytesItem->get() : 0;

            if (($filesPerHour > 0 && $fileCount + $files > $filesPerHour)
                || ($mbPerHour > 0 && $byteCount + $bytes > $mbPerHour * self::MB)) {
                $this->logger->notice('Nova Upload: Upload-Kontingent je Besucher erreicht', ['filesPerHour' => $filesPerHour, 'mbPerHour' => $mbPerHour]);

                return false;
            }

            $filesItem->set($fileCount + $files);
            $filesItem->expiresAfter(self::WINDOW + 60);
            $bytesItem->set($byteCount + $bytes);
            $bytesItem->expiresAfter(self::WINDOW + 60);
            $this->cache->saveDeferred($filesItem);
            $this->cache->saveDeferred($bytesItem);
            $this->cache->commit();
        } catch (\Throwable $e) {
            // Cache nicht verfügbar: Upload nicht blockieren, die Gesamtgrenze greift weiter
            $this->logger->warning('Nova Upload: Upload-Kontingent konnte nicht geprüft werden', ['exception' => $e]);
        }

        return true;
    }

    private function hasFreeTotal(int $bytes, ?string $salesChannelId): bool
    {
        $maxOpenMb = $this->int('productOpenMaxMb', $salesChannelId, 2048);
        if ($maxOpenMb <= 0) {
            return true;
        }

        $max = $maxOpenMb * self::MB;
        if ($this->store->openProductBytes() + $bytes <= $max) {
            return true;
        }

        // Grenze erreicht: verwaiste Warenkorb-Dateien vorzeitig löschen, dann erneut prüfen
        $deleted = $this->store->deleteOpenProductOlderThan(self::EMERGENCY_CLEANUP_HOURS);
        if ($deleted > 0) {
            $this->logger->warning('Nova Upload: Gesamtgrenze erreicht, alte offene Artikel-Uploads vorzeitig gelöscht', ['deleted' => $deleted, 'olderThanHours' => self::EMERGENCY_CLEANUP_HOURS]);
            if ($this->store->openProductBytes() + $bytes <= $max) {
                return true;
            }
        }

        $this->logger->warning('Nova Upload: Kontingent für offene Artikel-Uploads erreicht', ['maxMb' => $maxOpenMb]);
        $this->notifyAdministration($maxOpenMb);

        return false;
    }

    /**
     * Glocke in der Administration (höchstens alle 6 Stunden), damit die Sperre nicht unbemerkt bleibt
     */
    private function notifyAdministration(int $maxOpenMb): void
    {
        if ($this->notificationRepository === null) {
            return;
        }

        try {
            $item = $this->cache->getItem('nova_upload_quota_notified');
            if ($item->isHit()) {
                return;
            }
            $item->set(1);
            $item->expiresAfter(self::NOTIFY_INTERVAL);
            $this->cache->save($item);

            $message = \sprintf(
                'Nova Upload: Die Gesamtgrenze für Artikel-Dateien ohne Bestellung (%d MB) ist erreicht – neue Artikel-Uploads werden abgelehnt. Bitte unter Bestellungen → Uploads prüfen (ggf. Missbrauch) und die Grenze bzw. Aufbewahrungsfrist in der Plugin-Konfiguration anpassen.',
                $maxOpenMb
            );

            Context::createDefaultContext()->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($message): void {
                $this->notificationRepository?->create([[
                    'id' => Uuid::randomHex(),
                    'status' => 'warning',
                    'message' => $message,
                    'adminOnly' => true,
                    'requiredPrivileges' => [],
                ]], $context);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Upload: Benachrichtigung konnte nicht angelegt werden', ['exception' => $e]);
        }
    }

    private function int(string $key, ?string $salesChannelId, int $default): int
    {
        $value = $this->config->get('NovaUpload.config.' . $key, $salesChannelId);

        return is_numeric($value) ? (int) $value : $default;
    }
}
