<?php declare(strict_types=1);

namespace Nova\Upload\Controller;

use Nova\Upload\Service\UploadStore;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mein Konto → Anfragen & Dateien: hochgeladene Dateien dieses Verkaufskanals für freigeschaltete Konten.
 * Zugang nur mit Passwort-Login UND bestätigtem Code aus der Authenticator-App (Plugin Nova 2FA).
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class AccountUploadController extends StorefrontController
{
    public const ACCESS_FIELD = 'nova_upload_access';
    public const ACCESS_CONFIG = 'NovaUpload.config.downloadCustomerIds';

    /**
     * @param object|null $twoFactorGuard Nova\TwoFactor\Service\TwoFactorGuard, falls Nova 2FA installiert ist
     */
    public function __construct(
        private readonly GenericPageLoaderInterface $pageLoader,
        private readonly UploadStore $store,
        private readonly SystemConfigService $config,
        private readonly ?object $twoFactorGuard = null,
    ) {
    }

    #[Route(path: '/account/nova-uploads', name: 'frontend.nova_upload.account', defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: [Request::METHOD_GET])]
    public function list(Request $request, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        $denied = $this->access($request, $context, $customer);
        if ($denied !== null) {
            return $denied;
        }

        $term = mb_substr(trim($request->query->getString('term')), 0, 100);
        $page = max(1, $request->query->getInt('p', 1));
        $result = $this->store->list(['term' => $term, 'salesChannelId' => $context->getSalesChannelId()], $page, 50);

        return $this->renderStorefront('@NovaUpload/storefront/page/account/nova-uploads/index.html.twig', [
            'page' => $this->pageLoader->load($request, $context),
            'novaUploads' => $result['items'],
            'novaUploadTotal' => $result['total'],
            'novaUploadPage' => $page,
            'novaUploadPages' => (int) max(1, ceil($result['total'] / 50)),
            'novaUploadTerm' => $term,
        ]);
    }

    #[Route(path: '/account/nova-uploads/file/{id}', name: 'frontend.nova_upload.account.file', requirements: ['id' => '[0-9a-f]{32}'], defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true, PlatformRequest::ATTRIBUTE_NO_STORE => true], methods: [Request::METHOD_GET])]
    public function download(string $id, Request $request, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        $denied = $this->access($request, $context, $customer);
        if ($denied !== null) {
            return $denied;
        }

        $upload = $this->store->get($id);
        // Nur Dateien dieses Verkaufskanals
        $stream = $upload !== null && $upload['salesChannelId'] === $context->getSalesChannelId() ? $this->store->readStream($upload['path']) : null;
        if ($upload === null || $stream === null) {
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });

        $fallback = (string) preg_replace('/[^\x20-\x7e]/', '_', $upload['fileName']);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $upload['fileName'], $fallback));
        $response->headers->set('Content-Length', (string) $upload['size']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Alle Bedingungen für den Zugang; null = Zugang erlaubt.
     */
    private function access(Request $request, SalesChannelContext $context, CustomerEntity $customer): ?Response
    {
        $salesChannelId = $context->getSalesChannelId();
        if ($customer->getGuest() || !$this->config->getBool('NovaUpload.config.downloadArea', $salesChannelId) || !self::hasAccess($this->config, $customer->getId(), $customer->getCustomFields(), $salesChannelId)) {
            throw $this->createNotFoundException();
        }

        $guard = $this->twoFactorGuard;
        if ($guard === null || !method_exists($guard, 'isEnabled') || !method_exists($guard, 'isVerified')) {
            $this->addFlash(self::DANGER, $this->trans('novaUpload.account.missing2fa'));

            return $this->redirectToRoute('frontend.account.home.page');
        }

        if (!$guard->isEnabled($customer->getId())) {
            $this->addFlash(self::WARNING, $this->trans('novaUpload.account.needs2fa'));

            return $this->redirectToRoute('frontend.nova_2fa.page');
        }

        if (!$guard->isVerified($request, $customer->getId(), $salesChannelId)) {
            return $this->redirectToRoute('frontend.nova_2fa.verify', ['redirectTo' => $request->getRequestUri()]);
        }

        return null;
    }

    /**
     * Zugriff über die Kundenauswahl in der Plugin-Konfiguration oder das Zusatzfeld am Kunden
     *
     * @param array<string, mixed>|null $customFields
     */
    public static function hasAccess(SystemConfigService $config, string $customerId, ?array $customFields, ?string $salesChannelId): bool
    {
        if (!empty($customFields[self::ACCESS_FIELD])) {
            return true;
        }
        $ids = $config->get(self::ACCESS_CONFIG, $salesChannelId);

        return \is_array($ids) && \in_array($customerId, $ids, true);
    }
}
