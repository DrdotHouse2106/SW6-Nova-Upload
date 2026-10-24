<?php declare(strict_types=1);

namespace Nova\Upload\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ablage der Dateien im privaten Dateisystem des Plugins (files/plugins/nova_upload, nicht öffentlich)
 * und Verwaltung der Einträge in der Tabelle nova_upload.
 */
class UploadStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemOperator $filesystem,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{customerName?: ?string, customerEmail?: ?string, reference?: ?string, productId?: ?string, lineItemId?: ?string} $meta
     *
     * @return array{id: string, name: string}
     */
    public function store(UploadedFile $file, string $source, ?string $salesChannelId, array $meta = []): array
    {
        $id = Uuid::randomHex();
        $name = UploadRules::safeName($file->getClientOriginalName());
        // Ohne Endung gespeichert: die Datei kann auf dem Server nie als Skript o. Ä. ausgeführt werden
        $path = 'uploads/' . date('Y/m') . '/' . $id;

        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Datei konnte nicht gelesen werden: ' . $name);
        }

        try {
            $this->filesystem->writeStream($path, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->connection->insert('nova_upload', [
            'id' => Uuid::fromHexToBytes($id),
            'source' => $source,
            'sales_channel_id' => $salesChannelId !== null && Uuid::isValid($salesChannelId) ? Uuid::fromHexToBytes($salesChannelId) : null,
            'path' => $path,
            'file_name' => $name,
            'mime_type' => mb_substr($this->mimeType($file), 0, 255),
            'size' => (int) $file->getSize(),
            'customer_name' => $this->cut($meta['customerName'] ?? null),
            'customer_email' => $this->cut($meta['customerEmail'] ?? null),
            'reference' => $this->cut($meta['reference'] ?? null),
            'product_id' => isset($meta['productId']) && Uuid::isValid($meta['productId']) ? Uuid::fromHexToBytes($meta['productId']) : null,
            'line_item_id' => $meta['lineItemId'] ?? null,
            'created_at' => $this->now(),
        ]);

        return ['id' => $id, 'name' => $name];
    }

    /**
     * Uploads aus dem Warenkorb der Bestellung zuordnen (ab dann gilt die Aufbewahrung für Bestellungen).
     *
     * @param list<string> $uploadIds
     */
    public function assignOrder(array $uploadIds, string $orderId, string $orderNumber, ?string $customerName, ?string $customerEmail): void
    {
        $ids = array_values(array_filter($uploadIds, static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE `nova_upload` SET `order_id` = :orderId, `order_number` = :orderNumber, `assigned_at` = :now,
                `customer_name` = :name, `customer_email` = :email
             WHERE `id` IN (:ids) AND `order_id` IS NULL',
            [
                'orderId' => Uuid::fromHexToBytes($orderId),
                'orderNumber' => $orderNumber,
                'now' => $this->now(),
                'name' => $this->cut($customerName),
                'email' => $this->cut($customerEmail),
                'ids' => Uuid::fromHexToBytesList($ids),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * @param array{term?: string, source?: string, orderId?: string} $filter
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(array $filter, int $page, int $limit): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (\in_array($filter['source'] ?? '', [UploadRules::SOURCE_CONTACT, UploadRules::SOURCE_PRODUCT], true)) {
            $where[] = 'u.`source` = :source';
            $params['source'] = $filter['source'];
        }

        if (isset($filter['orderId']) && Uuid::isValid($filter['orderId'])) {
            $where[] = 'u.`order_id` = :orderId';
            $params['orderId'] = Uuid::fromHexToBytes($filter['orderId']);
        }

        $term = trim($filter['term'] ?? '');
        if ($term !== '') {
            $where[] = '(u.`file_name` LIKE :term OR u.`customer_name` LIKE :term OR u.`customer_email` LIKE :term
                OR u.`reference` LIKE :term OR u.`order_number` LIKE :term OR LOWER(HEX(u.`id`)) = :hex)';
            $params['term'] = '%' . addcslashes($term, '%_\\') . '%';
            $params['hex'] = mb_strtolower($term);
        }

        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(u.`id`)) AS id, u.`source`, LOWER(HEX(u.`sales_channel_id`)) AS salesChannelId,
                    u.`file_name` AS fileName, u.`mime_type` AS mimeType, u.`size`, u.`customer_name` AS customerName,
                    u.`customer_email` AS customerEmail, u.`reference`, LOWER(HEX(u.`product_id`)) AS productId,
                    LOWER(HEX(u.`order_id`)) AS orderId, u.`order_number` AS orderNumber, u.`created_at` AS createdAt
             FROM `nova_upload` u WHERE ' . $sqlWhere . '
             ORDER BY u.`created_at` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `nova_upload` u WHERE ' . $sqlWhere, $params);

        return ['items' => array_map(static function (array $row): array {
            $row['size'] = (int) $row['size'];

            return $row;
        }, $rows), 'total' => $total];
    }

    /**
     * @return array{path: string, fileName: string, mimeType: string, size: int}|null
     */
    public function get(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT `path`, `file_name` AS fileName, `mime_type` AS mimeType, `size` FROM `nova_upload` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        if ($row === false) {
            return null;
        }

        return ['path' => (string) $row['path'], 'fileName' => (string) $row['fileName'], 'mimeType' => (string) $row['mimeType'], 'size' => (int) $row['size']];
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        if (!$this->filesystem->fileExists($path)) {
            return null;
        }

        return $this->filesystem->readStream($path);
    }

    public function delete(string $id): bool
    {
        $row = $this->get($id);
        if ($row === null) {
            return false;
        }

        $this->deleteFile($row['path']);
        $this->connection->delete('nova_upload', ['id' => Uuid::fromHexToBytes($id)]);

        return true;
    }

    /**
     * Abgelaufene Dateien löschen – Fristen pro Verkaufskanal aus der Plugin-Konfiguration.
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $channels = $this->connection->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(`sales_channel_id`)) FROM `nova_upload`');

        foreach ($channels as $channel) {
            $salesChannelId = \is_string($channel) && $channel !== '' ? $channel : null;
            $channelWhere = $salesChannelId !== null ? '`sales_channel_id` = :sc' : '`sales_channel_id` IS NULL';
            $params = $salesChannelId !== null ? ['sc' => Uuid::fromHexToBytes($salesChannelId)] : [];

            $rules = [
                // Kontaktformular
                ['days' => $this->days('keepDaysContact', $salesChannelId, 180), 'where' => "`source` = 'contact'", 'column' => '`created_at`'],
                // Artikel-Uploads ohne Bestellung (Warenkorb verlassen)
                ['days' => $this->days('keepDaysOpen', $salesChannelId, 14), 'where' => "`source` = 'product' AND `order_id` IS NULL", 'column' => '`created_at`'],
                // Artikel-Uploads einer Bestellung (0 = behalten)
                ['days' => $this->days('keepDaysOrder', $salesChannelId, 0), 'where' => "`source` = 'product' AND `order_id` IS NOT NULL", 'column' => '`assigned_at`'],
            ];

            foreach ($rules as $rule) {
                if ($rule['days'] <= 0) {
                    continue;
                }

                $before = (new \DateTimeImmutable())->modify('-' . $rule['days'] . ' days')->format('Y-m-d H:i:s.v');
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT `id`, `path` FROM `nova_upload` WHERE ' . $channelWhere . ' AND ' . $rule['where'] . ' AND ' . $rule['column'] . ' < :before LIMIT 500',
                    $params + ['before' => $before]
                );

                foreach ($rows as $row) {
                    $this->deleteFile((string) $row['path']);
                    $this->connection->delete('nova_upload', ['id' => $row['id']]);
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    /** Beim Deinstallieren ohne „Daten behalten“ */
    public function deleteAllFiles(): void
    {
        try {
            $this->filesystem->deleteDirectory('uploads');
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Upload: Dateien konnten nicht gelöscht werden', ['exception' => $e]);
        }
    }

    private function deleteFile(string $path): void
    {
        try {
            if ($this->filesystem->fileExists($path)) {
                $this->filesystem->delete($path);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Upload: Datei konnte nicht gelöscht werden', ['path' => $path, 'exception' => $e]);
        }
    }

    private function days(string $key, ?string $salesChannelId, int $default): int
    {
        $value = $this->config->get('NovaUpload.config.' . $key, $salesChannelId);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function mimeType(UploadedFile $file): string
    {
        try {
            return $file->getMimeType() ?? 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }

    private function cut(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
