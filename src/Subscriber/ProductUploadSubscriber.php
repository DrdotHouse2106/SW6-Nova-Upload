<?php declare(strict_types=1);

namespace Nova\Upload\Subscriber;

use Nova\Upload\Service\UploadRules;
use Nova\Upload\Service\UploadStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Datei-Upload beim Artikel (z. B. Logo für individuelle Artikel), pro Artikel über Zusatzfelder einschaltbar.
 *
 * - In den Warenkorb: Dateien prüfen und geschützt speichern; die Position bekommt eine eigene ID
 *   (gleicher Artikel mit anderer Datei = eigene Zeile) und die Dateinamen im Payload.
 * - Bestellung: Uploads der Bestellung zuordnen (Anzeige in der Bestellung in der Administration).
 */
class ProductUploadSubscriber implements EventSubscriberInterface
{
    public const FIELD = 'novaUpload';
    public const ROUTE = 'frontend.checkout.line-item.add';
    public const PAYLOAD = 'novaUpload';

    public const CF_ACTIVE = 'nova_upload_active';
    public const CF_REQUIRED = 'nova_upload_required';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly UploadRules $rules,
        private readonly UploadStore $store,
        private readonly EntityRepository $productRepository,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get('_route') !== self::ROUTE) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $lineItems = $request->request->all()['lineItems'] ?? null;
        if (!$context instanceof SalesChannelContext || !\is_array($lineItems) || $lineItems === []) {
            return;
        }

        $products = $this->uploadProducts($lineItems, $context);
        if ($products === []) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();

        // Erst alles prüfen, dann speichern
        foreach ($products as $key => $product) {
            $files = UploadRules::files($request, self::FIELD, (string) $key);
            $error = $this->rules->validate($files, UploadRules::SOURCE_PRODUCT, $salesChannelId);

            if ($error === null && $files === [] && $this->flag($product, self::CF_REQUIRED)) {
                $error = $this->rules->trans('novaUpload.errorRequired');
            }

            if ($error !== null) {
                $this->fail($event, $request, $error);

                return;
            }
        }

        foreach ($products as $key => $product) {
            $files = UploadRules::files($request, self::FIELD, (string) $key);
            if ($files === []) {
                continue;
            }

            $lineItemId = Uuid::randomHex();
            $stored = $this->storeFiles($files, $salesChannelId, $product, $lineItemId, $context);
            if ($stored === []) {
                $this->fail($event, $request, $this->rules->trans('novaUpload.errorStore'));

                return;
            }

            $item = \is_array($lineItems[$key]) ? $lineItems[$key] : [];
            $payload = \is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $payload[self::PAYLOAD] = $stored;
            $item['payload'] = $payload;
            // Eigene Position: derselbe Artikel mit anderer Datei wird nicht zusammengezählt
            $item['id'] = $lineItemId;
            $lineItems[$key] = $item;
        }

        $request->request->set('lineItems', $lineItems);
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $ids = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $uploads = $lineItem->getPayload()[self::PAYLOAD] ?? null;
            if (!\is_array($uploads)) {
                continue;
            }

            foreach ($uploads as $upload) {
                if (\is_array($upload) && \is_string($upload['id'] ?? null)) {
                    $ids[] = $upload['id'];
                }
            }
        }

        if ($ids === []) {
            return;
        }

        $customer = $order->getOrderCustomer();
        $name = $customer !== null ? trim($customer->getFirstName() . ' ' . $customer->getLastName()) : null;

        try {
            $this->store->assignOrder($ids, $order->getId(), (string) $order->getOrderNumber(), $name, $customer?->getEmail());
        } catch (\Throwable $e) {
            $this->logger->error('Nova Upload: Uploads konnten der Bestellung nicht zugeordnet werden', ['order' => $order->getOrderNumber(), 'exception' => $e]);
        }
    }

    /**
     * Artikel im Request, bei denen der Upload eingeschaltet ist – Schlüssel wie in lineItems.
     *
     * @param array<mixed> $lineItems
     *
     * @return array<string, ProductEntity>
     */
    private function uploadProducts(array $lineItems, SalesChannelContext $context): array
    {
        $map = [];
        foreach ($lineItems as $key => $item) {
            if (!\is_array($item) || ($item['type'] ?? 'product') !== 'product') {
                continue;
            }

            $productId = $item['referencedId'] ?? $item['id'] ?? $key;
            if (\is_string($productId) && Uuid::isValid($productId)) {
                $map[(string) $key] = $productId;
            }
        }

        if ($map === []) {
            return [];
        }

        $criteria = new Criteria(array_values(array_unique($map)));
        $innerContext = clone $context->getContext();
        $innerContext->setConsiderInheritance(true);
        $products = $this->productRepository->search($criteria, $innerContext)->getEntities();

        $result = [];
        foreach ($map as $key => $productId) {
            $product = $products->get($productId);
            if ($product instanceof ProductEntity && $this->flag($product, self::CF_ACTIVE)) {
                $result[$key] = $product;
            }
        }

        return $result;
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return list<array{id: string, name: string}>
     */
    private function storeFiles(array $files, string $salesChannelId, ProductEntity $product, string $lineItemId, SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        $stored = [];

        foreach ($files as $file) {
            try {
                $stored[] = $this->store->store($file, UploadRules::SOURCE_PRODUCT, $salesChannelId, [
                    'customerName' => $customer !== null ? trim($customer->getFirstName() . ' ' . $customer->getLastName()) : null,
                    'customerEmail' => $customer?->getEmail(),
                    'reference' => trim($product->getProductNumber() . ' ' . ($product->getTranslation('name') ?? $product->getName() ?? '')),
                    'productId' => $product->getId(),
                    'lineItemId' => $lineItemId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Nova Upload: Datei zum Artikel konnte nicht gespeichert werden', ['exception' => $e]);
            }
        }

        return $stored;
    }

    private function flag(ProductEntity $product, string $field): bool
    {
        $fields = $product->getTranslation('customFields');
        if (!\is_array($fields) || !\array_key_exists($field, $fields)) {
            $fields = $product->getCustomFields() ?? [];
        }

        return (bool) ($fields[$field] ?? false);
    }

    /**
     * Nicht in den Warenkorb legen; Meldung im Warenkorb (Offcanvas) bzw. auf der nächsten Seite.
     */
    private function fail(ControllerEvent $event, Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', $message);
        }

        $route = $request->request->get('redirectTo');
        $route = \is_string($route) && str_starts_with($route, 'frontend.') ? $route : 'frontend.cart.offcanvas';
        $parameters = $request->request->get('redirectParameters');
        $parameters = \is_string($parameters) ? json_decode($parameters, true) : null;

        try {
            $url = $this->router->generate($route, \is_array($parameters) ? $parameters : []);
        } catch (\Throwable) {
            $url = $this->router->generate('frontend.cart.offcanvas');
        }

        $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
    }
}
