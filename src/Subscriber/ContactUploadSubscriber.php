<?php declare(strict_types=1);

namespace Nova\Upload\Subscriber;

use Nova\Upload\Service\UploadRules;
use Nova\Upload\Service\UploadStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\ContactForm\Event\ContactFormEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Datei-Anhänge im Shopware-Kontaktformular.
 *
 * 1. Vor dem Absenden: Anzahl, Typ und Größe prüfen – bei Fehlern antwortet das Formular mit einer Meldung.
 * 2. Nach erfolgreicher Prüfung des Formulars (ContactFormEvent): Dateien geschützt speichern.
 * 3. Mail an den Shop: kurzer Hinweis mit Dateinamen und Link in die Administration – keine Anhänge.
 */
class ContactUploadSubscriber implements EventSubscriberInterface, ResetInterface
{
    public const FIELD = 'novaUploadFiles';
    public const ROUTE = 'frontend.form.contact.send';

    private ?Request $request = null;

    /** @var list<array{id: string, name: string}> */
    private array $stored = [];

    private string $adminUrl = '';

    public function __construct(
        private readonly UploadRules $rules,
        private readonly UploadStore $store,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            // Vor dem Flow, der die Mail verschickt
            ContactFormEvent::EVENT_NAME => ['onContactForm', 100],
            MailBeforeValidateEvent::class => 'onMail',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get('_route') !== self::ROUTE) {
            return;
        }

        $this->reset();
        $salesChannelId = $this->salesChannelId($request);
        if (!$this->rules->isContactActive($salesChannelId)) {
            return;
        }

        $files = UploadRules::files($request, self::FIELD);
        $error = $this->rules->validate($files, UploadRules::SOURCE_CONTACT, $salesChannelId);
        if ($error === null) {
            $this->request = $files !== [] ? $request : null;

            return;
        }

        $html = '<div role="alert" class="alert alert-danger d-flex align-items-center"><div class="alert-content-container"><div class="alert-content">'
            . htmlspecialchars($error, \ENT_QUOTES)
            . '</div></div></div>';

        $event->setController(static fn (): JsonResponse => new JsonResponse([['type' => 'danger', 'alert' => $html]]));
    }

    public function onContactForm(ContactFormEvent $event): void
    {
        $request = $this->request;
        if ($request === null) {
            return;
        }
        $this->request = null;

        $data = $event->getContactFormData();
        $name = trim(\sprintf('%s %s', $data['firstName'] ?? '', $data['lastName'] ?? ''));
        $meta = [
            'customerName' => $name,
            'customerEmail' => \is_string($data['email'] ?? null) ? $data['email'] : null,
            'reference' => \is_string($data['subject'] ?? null) ? $data['subject'] : null,
        ];

        foreach (UploadRules::files($request, self::FIELD) as $file) {
            try {
                $this->stored[] = $this->store->store($file, UploadRules::SOURCE_CONTACT, $event->getSalesChannelId(), $meta);
            } catch (\Throwable $e) {
                $this->logger->error('Nova Upload: Datei aus dem Kontaktformular konnte nicht gespeichert werden', ['exception' => $e]);
            }
        }

        $this->adminUrl = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/admin#/nova/upload/index';
    }

    public function onMail(MailBeforeValidateEvent $event): void
    {
        if ($this->stored === []) {
            return;
        }

        $data = $event->getData();
        $salesChannelId = \is_string($data['salesChannelId'] ?? null) ? $data['salesChannelId'] : null;
        if (!($this->config->get('NovaUpload.config.mailNotice', $salesChannelId) ?? true)) {
            return;
        }

        // Kopie an den Absender (falls ein Flow sie verschickt) ohne Hinweis
        $sender = '';
        $templateData = $event->getTemplateData();
        $formData = $templateData['contactFormData'] ?? [];
        if (\is_array($formData) && \is_string($formData['email'] ?? null)) {
            $sender = mb_strtolower(trim($formData['email']));
        }
        $recipients = \is_array($data['recipients'] ?? null) ? array_map('mb_strtolower', array_map('strval', array_keys($data['recipients']))) : [];
        if ($sender !== '' && \in_array($sender, $recipients, true)) {
            return;
        }

        $names = array_map(static fn (array $file): string => $file['name'], $this->stored);
        $search = $this->adminUrl !== '' ? $this->adminUrl . '?term=' . rawurlencode($this->stored[0]['id']) : '';
        $headline = $this->rules->trans('novaUpload.mail.headline');
        $text = $this->rules->trans('novaUpload.mail.text');

        $html = '<hr><p><strong>' . htmlspecialchars($headline, \ENT_QUOTES) . '</strong><br>'
            . implode('<br>', array_map(static fn (string $name): string => '• ' . htmlspecialchars($name, \ENT_QUOTES), $names))
            . '</p><p>' . htmlspecialchars($text, \ENT_QUOTES)
            . ($search !== '' ? '<br><a href="' . htmlspecialchars($search, \ENT_QUOTES) . '">' . htmlspecialchars($search, \ENT_QUOTES) . '</a>' : '')
            . '</p>';
        $plain = "\n\n" . $headline . "\n" . implode("\n", array_map(static fn (string $name): string => '- ' . $name, $names))
            . "\n" . $text . ($search !== '' ? "\n" . $search : '');

        // Inhalte sind Twig-Vorlagen – den Zusatz unverändert durchreichen
        if (\is_string($data['contentHtml'] ?? null)) {
            $event->addData('contentHtml', $data['contentHtml'] . '{% verbatim %}' . $html . '{% endverbatim %}');
        }
        if (\is_string($data['contentPlain'] ?? null)) {
            $event->addData('contentPlain', $data['contentPlain'] . '{% verbatim %}' . $plain . '{% endverbatim %}');
        }
    }

    public function reset(): void
    {
        $this->request = null;
        $this->stored = [];
        $this->adminUrl = '';
    }

    private function salesChannelId(Request $request): ?string
    {
        $id = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        return \is_string($id) && $id !== '' ? $id : null;
    }
}
