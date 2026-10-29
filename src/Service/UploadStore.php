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
     * @param array{customerName?: ?string, customerEmail?: ?string, reference?: ?string, productId?: ?string, lineItemId?: ?string, shareConsent?: bool} $meta
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

        try {
            $this->insertRow($id, $source, $salesChannelId, $path, $name, $file, $meta);
        } catch (\Throwable $e) {
            // Keine verwaiste Datei ohne Datensatz zurücklassen (die findet der Cleanup nie)
            $this->deleteFile($path);

            throw $e;
        }

        return ['id' => $id, 'name' => $name];
    }

    /**
     * @param array{customerName?: ?string, customerEmail?: ?string, reference?: ?string, productId?: ?string, lineItemId?: ?string, shareConsent?: bool} $meta
     */
    private function insertRow(string $id, string $source, ?string $salesChannelId, string $path, string $name, UploadedFile $file, array $meta): void
    {
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
            'share_consent' => !empty($meta['shareConsent']) ? 1 : 0,
            'product_id' => isset($meta['productId']) && Uuid::isValid($meta['productId']) ? Uuid::fromHexToBytes($meta['productId']) : null,
            'line_item_id' => $meta['lineItemId'] ?? null,
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Uploads aus dem Warenkorb der Bestellung zuordnen (ab dann gilt die Aufbewahrung für Bestellungen).
     * Zugeordnet wird nur, was beim Hinzufügen für genau diese Position gespeichert wurde.
     *
     * @param array<string, string> $uploads Upload-ID => ID der Warenkorb-Position
     */
    public function assignOrder(array $uploads, string $orderId, string $orderNumber, ?string $customerName, ?string $customerEmail, ?string $salesChannelId = null): void
    {
        $ids = array_values(array_filter(array_map('strval', array_keys($uploads)), static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(`id`)) AS id, `line_item_id` AS lineItemId, LOWER(HEX(`sales_channel_id`)) AS salesChannelId
             FROM `nova_upload` WHERE `id` IN (:ids) AND `source` = 'product' AND `order_id` IS NULL",
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $matched = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if ((string) $row['lineItemId'] === '' || !hash_equals((string) $row['lineItemId'], (string) ($uploads[$id] ?? ''))) {
                continue;
            }
            if ($salesChannelId !== null && $row['salesChannelId'] !== null && $row['salesChannelId'] !== $salesChannelId) {
                continue;
            }
            $matched[] = $id;
        }

        if ($matched === []) {
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
                'ids' => Uuid::fromHexToBytesList($matched),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /** Gesamtgröße der Artikel-Uploads, die noch keiner Bestellung zugeordnet sind (Bytes) */
    public function openProductBytes(): int
    {
        return (int) $this->connection->fetchOne("SELECT COALESCE(SUM(`size`), 0) FROM `nova_upload` WHERE `source` = 'product' AND `order_id` IS NULL");
    }

    /**
     * Kundenkonto gelöscht: Anfragen und Warenkorb-Dateien dieser E-Mail-Adresse löschen.
     * Dateien von Bestellungen bleiben bei der Bestellung (eigene Frist bzw. Löschung mit der Bestellung).
     */
    public function deleteForEmail(string $email): int
    {
        $email = trim($email);
        if ($email === '') {
            return 0;
        }

        return $this->deleteWhere('`customer_email` = :email AND `order_id` IS NULL', ['email' => $email]);
    }

    /**
     * Bestellungen gelöscht: deren Dateien löschen.
     *
     * @param list<string> $orderIds
     */
    public function deleteForOrders(array $orderIds): int
    {
        $ids = array_values(array_filter($orderIds, static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return 0;
        }

        return $this->deleteWhere('`order_id` IN (:ids)', ['ids' => Uuid::fromHexToBytesList($ids)], ['ids' => ArrayParameterType::BINARY]);
    }

    /**
     * @param array{term?: string, source?: string, orderId?: string, salesChannelId?: string} $filter
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

        if (isset($filter['salesChannelId']) && Uuid::isValid($filter['salesChannelId'])) {
            $where[] = 'u.`sales_channel_id` = :salesChannelId';
            $params['salesChannelId'] = Uuid::fromHexToBytes($filter['salesChannelId']);
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
                    u.`customer_email` AS customerEmail, u.`reference`, u.`share_consent` AS shareConsent, LOWER(HEX(u.`product_id`)) AS productId,
                    LOWER(HEX(u.`order_id`)) AS orderId, u.`order_number` AS orderNumber, u.`created_at` AS createdAt
             FROM `nova_upload` u WHERE ' . $sqlWhere . '
             ORDER BY u.`created_at` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `nova_upload` u WHERE ' . $sqlWhere, $params);

        return ['items' => array_map(static function (array $row): array {
            $row['size'] = (int) $row['size'];
            $row['shareConsent'] = (bool) $row['shareConsent'];

            return $row;
        }, $rows), 'total' => $total];
    }

    /**
     * @return array{path: string, fileName: string, mimeType: string, size: int, salesChannelId: ?string}|null
     */
    public function get(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT `path`, `file_name` AS fileName, `mime_type` AS mimeType, `size`, LOWER(HEX(`sales_channel_id`)) AS salesChannelId FROM `nova_upload` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        if ($row === false) {
            return null;
        }

        return [
            'path' => (string) $row['path'],
            'fileName' => (string) $row['fileName'],
            'mimeType' => (string) $row['mimeType'],
            'size' => (int) $row['size'],
            'salesChannelId' => \is_string($row['salesChannelId']) && $row['salesChannelId'] !== '' ? $row['salesChannelId'] : null,
        ];
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
                ['days' => $this->days('keepDaysOrder', $salesChannelId, 730), 'where' => "`source` = 'product' AND `order_id` IS NOT NULL", 'column' => '`assigned_at`'],
            ];

            foreach ($rules as $rule) {
                if ($rule['days'] <= 0) {
                    continue;
                }

                $before = (new \DateTimeImmutable())->modify('-' . $rule['days'] . ' days')->format('Y-m-d H:i:s.v');
                $deleted += $this->deleteWhere($channelWhere . ' AND ' . $rule['where'] . ' AND ' . $rule['column'] . ' < :before', $params + ['before' => $before]);
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

    /**
     * Löscht Dateien und Einträge in Blöcken zu 500 (höchstens 50 Blöcke je Aufruf).
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     */
    private function deleteWhere(string $where, array $params, array $types = []): int
    {
        $deleted = 0;

        for ($round = 0; $round < 50; ++$round) {
            $rows = $this->connection->fetchAllAssociative('SELECT `id`, `path` FROM `nova_upload` WHERE ' . $where . ' LIMIT 500', $params, $types);

            foreach ($rows as $row) {
                $this->deleteFile((string) $row['path']);
                $this->connection->delete('nova_upload', ['id' => $row['id']]);
                ++$deleted;
            }

            if (\count($rows) < 500) {
                break;
            }
        }

        return $deleted;
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
