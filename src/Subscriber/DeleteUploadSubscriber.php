<?php declare(strict_types=1);

namespace Nova\Upload\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nova\Upload\Service\UploadStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Löschkopplung (DSGVO):
 * - Kundenkonto gelöscht (Administration oder „Konto löschen“): Anfragen und Warenkorb-Dateien dieser
 *   E-Mail-Adresse löschen, sofern kein anderes Konto mit der Adresse bleibt. Gastkonten (Shopwares
 *   automatische Bereinigung) lösen nichts aus.
 * - Bestellung gelöscht: deren Dateien löschen.
 */
class DeleteUploadSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UploadStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityDeleteEvent::class => 'beforeDelete'];
    }

    public function beforeDelete(EntityDeleteEvent $event): void
    {
        $emails = $this->customerEmails($event->getIds('customer'));
        $orderIds = $this->liveOrderIds($event);

        if ($emails === [] && $orderIds === []) {
            return;
        }

        $event->addSuccess(function () use ($emails, $orderIds): void {
            try {
                $deleted = $orderIds !== [] ? $this->store->deleteForOrders($orderIds) : 0;

                foreach ($emails as $email) {
                    $remaining = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `customer` WHERE `email` = :email', ['email' => $email]);
                    if ($remaining === 0) {
                        $deleted += $this->store->deleteForEmail($email);
                    }
                }

                if ($deleted > 0) {
                    $this->logger->info('Nova Upload: Dateien zu gelöschten Kunden/Bestellungen entfernt', ['count' => $deleted]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Nova Upload: Dateien zu gelöschten Kunden/Bestellungen konnten nicht entfernt werden', ['exception' => $e]);
            }
        });
    }

    /**
     * @param array<array<string, string>|string> $ids
     *
     * @return list<string>
     */
    private function customerEmails(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn ($id): bool => \is_string($id) && Uuid::isValid($id)));
        if ($ids === []) {
            return [];
        }

        try {
            $emails = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT `email` FROM `customer` WHERE `id` IN (:ids) AND `guest` = 0',
                ['ids' => Uuid::fromHexToBytesList($ids)],
                ['ids' => ArrayParameterType::BINARY]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Nova Upload: Kunden zum Löschen konnten nicht gelesen werden', ['exception' => $e]);

            return [];
        }

        return array_values(array_filter(array_map('strval', $emails), static fn (string $email): bool => trim($email) !== ''));
    }

    /**
     * Nur die Live-Version; Entwurfsversionen beim Bearbeiten einer Bestellung zählen nicht.
     *
     * @return list<string>
     */
    private function liveOrderIds(EntityDeleteEvent $event): array
    {
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $result = [];

        foreach ($event->getCommands() as $command) {
            if ($command->getEntityName() !== 'order') {
                continue;
            }

            $primaryKey = $command->getPrimaryKey();
            $id = $primaryKey['id'] ?? null;
            if (($primaryKey['version_id'] ?? null) === $live && \is_string($id) && \strlen($id) === 16) {
                $result[] = Uuid::fromBytesToHex($id);
            }
        }

        return array_values(array_unique($result));
    }
}
