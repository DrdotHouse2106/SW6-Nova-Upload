<?php declare(strict_types=1);

namespace Nova\Upload\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kontingent für Artikel-Uploads (ohne Anmeldung möglich): Dateien je Besucher (IP) und Stunde
 * sowie Gesamtgröße aller noch keiner Bestellung zugeordneten Uploads – schützt den Speicherplatz.
 */
class UploadQuota
{
    private const WINDOW = 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly UploadStore $store,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function acceptProductUploads(Request $request, int $files, ?string $salesChannelId): bool
    {
        $maxOpenMb = $this->int('productOpenMaxMb', $salesChannelId, 2048);
        if ($maxOpenMb > 0 && $this->store->openProductBytes() >= $maxOpenMb * 1024 * 1024) {
            $this->logger->warning('Nova Upload: Kontingent für offene Artikel-Uploads erreicht', ['maxMb' => $maxOpenMb]);

            return false;
        }

        $perHour = $this->int('productUploadsPerHour', $salesChannelId, 20);
        if ($perHour <= 0) {
            return true;
        }

        $window = intdiv(time(), self::WINDOW);
        $key = 'nova_upload_quota_' . hash('sha256', (string) $request->getClientIp()) . '_' . $window;

        try {
            $item = $this->cache->getItem($key);
            $count = $item->isHit() && \is_int($item->get()) ? $item->get() : 0;

            if ($count + $files > $perHour) {
                $this->logger->notice('Nova Upload: Upload-Kontingent je Besucher erreicht', ['perHour' => $perHour]);

                return false;
            }

            $item->set($count + $files);
            $item->expiresAfter(self::WINDOW + 60);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            // Cache nicht verfügbar: Upload nicht blockieren, die Gesamtgrenze greift weiter
            $this->logger->warning('Nova Upload: Upload-Kontingent konnte nicht geprüft werden', ['exception' => $e]);
        }

        return true;
    }

    private function int(string $key, ?string $salesChannelId, int $default): int
    {
        $value = $this->config->get('NovaUpload.config.' . $key, $salesChannelId);

        return is_numeric($value) ? (int) $value : $default;
    }
}
