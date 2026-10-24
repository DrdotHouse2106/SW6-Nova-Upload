<?php declare(strict_types=1);

namespace Nova\Upload\Controller;

use Nova\Upload\Service\UploadStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für hochgeladene Dateien (Bestellungen → Uploads und Karte in der Bestellung).
 * Ansehen und Herunterladen mit dem Recht „Bestellungen ansehen“, Löschen mit „Bestellungen löschen“.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminUploadController
{
    public function __construct(
        private readonly UploadStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/_action/nova-upload/list', name: 'api.action.nova_upload.list', methods: ['GET'])]
    public function list(Request $request, Context $context): JsonResponse
    {
        if (!$this->allowed($context, 'order:read')) {
            return $this->denied();
        }

        try {
            return new JsonResponse($this->store->list(
                [
                    'term' => $request->query->getString('term'),
                    'source' => $request->query->getString('source'),
                    'orderId' => $request->query->getString('orderId'),
                ],
                $request->query->getInt('page', 1),
                $request->query->getInt('limit', 25)
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Nova Upload: Liste konnte nicht geladen werden', ['exception' => $e]);

            return new JsonResponse(['message' => 'Interner Fehler. Details stehen im Shopware-Log.'], 500);
        }
    }

    #[Route(path: '/api/_action/nova-upload/{id}/download', name: 'api.action.nova_upload.download', requirements: ['id' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function download(string $id, Context $context): Response
    {
        if (!$this->allowed($context, 'order:read')) {
            return $this->denied();
        }

        $upload = $this->store->get($id);
        $stream = $upload !== null ? $this->store->readStream($upload['path']) : null;
        if ($upload === null || $stream === null) {
            return new JsonResponse(['message' => 'Datei nicht gefunden.'], 404);
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });

        $fallback = (string) preg_replace('/[^\x20-\x7e]/', '_', $upload['fileName']);
        // Immer als Download, nie im Browser anzeigen (z. B. SVG mit Skript)
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $upload['fileName'], $fallback));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Length', (string) $upload['size']);

        return $response;
    }

    #[Route(path: '/api/_action/nova-upload/{id}', name: 'api.action.nova_upload.delete', requirements: ['id' => '[0-9a-f]{32}'], methods: ['DELETE'])]
    public function delete(string $id, Context $context): JsonResponse
    {
        if (!$this->allowed($context, 'order:delete')) {
            return $this->denied();
        }

        return $this->store->delete($id)
            ? new JsonResponse(['deleted' => true])
            : new JsonResponse(['message' => 'Datei nicht gefunden.'], 404);
    }

    private function allowed(Context $context, string $privilege): bool
    {
        $source = $context->getSource();

        return $source instanceof AdminApiSource && $source->getUserId() !== null && $source->isAllowed($privilege);
    }

    private function denied(): JsonResponse
    {
        return new JsonResponse(['message' => 'Keine Berechtigung.'], 403);
    }
}
