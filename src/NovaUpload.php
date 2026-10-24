<?php declare(strict_types=1);

namespace Nova\Upload;

use Doctrine\DBAL\Connection;
use Nova\Upload\Service\UploadStore;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Nova Upload – Datei-Uploads im Kontaktformular und bei Artikeln, geschützt im Shop gespeichert.
 */
class NovaUpload extends Plugin
{
    /** Feste IDs, damit Installation und Update dieselben Zusatzfelder pflegen */
    private const SET_ID = '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b5a';
    private const SET_RELATION_ID = '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b5b';
    private const FIELDS = [
        'nova_upload_active' => ['id' => '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b60', 'type' => CustomFieldTypes::BOOL, 'position' => 1,
            'de' => 'Datei-Upload anbieten', 'en' => 'Offer file upload',
            'deHelp' => 'Zeigt auf der Produktseite ein Feld zum Hochladen (z. B. Logo). Die Datei hängt an der Warenkorb-Position und an der Bestellung.',
            'enHelp' => 'Shows an upload field on the product page (e.g. logo). The file is attached to the cart position and the order.'],
        'nova_upload_required' => ['id' => '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b61', 'type' => CustomFieldTypes::BOOL, 'position' => 2,
            'de' => 'Datei ist Pflicht', 'en' => 'File is required',
            'deHelp' => 'Ohne Datei kann der Artikel nicht in den Warenkorb gelegt werden. In Listen erscheint dann „Details“ statt „In den Warenkorb“.',
            'enHelp' => 'The product cannot be added to the cart without a file. Listings show "Details" instead of "Add to cart".'],
        'nova_upload_label' => ['id' => '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b62', 'type' => CustomFieldTypes::TEXT, 'position' => 3,
            'de' => 'Bezeichnung des Feldes', 'en' => 'Field label',
            'deHelp' => 'z. B. „Ihr Logo“. Leer = „Datei hochladen“.',
            'enHelp' => 'e.g. "Your logo". Empty = "Upload file".'],
        'nova_upload_hint' => ['id' => '7a1c3e0b5d9f4c2e8b6a4d1f0e9c7b63', 'type' => CustomFieldTypes::TEXT, 'position' => 4,
            'de' => 'Hinweis unter dem Feld', 'en' => 'Hint below the field',
            'deHelp' => 'z. B. „Am besten als SVG, PDF oder PNG mit transparentem Hintergrund“. Leer = erlaubte Formate und Größe.',
            'enHelp' => 'e.g. "Preferably SVG, PDF or PNG with transparent background". Empty = allowed formats and size.'],
    ];

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->upsertCustomFields($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $store = $this->container?->get(UploadStore::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if ($store instanceof UploadStore) {
            $store->deleteAllFiles();
        }

        $repository = $this->container?->get('custom_field_set.repository');
        if ($repository instanceof EntityRepository) {
            $repository->delete([['id' => self::SET_ID]], $uninstallContext->getContext());
        }

        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS `nova_upload`');
        }
    }

    private function upsertCustomFields(Context $context): void
    {
        $repository = $this->container?->get('custom_field_set.repository');
        if (!$repository instanceof EntityRepository) {
            return;
        }

        $fields = [];
        foreach (self::FIELDS as $name => $field) {
            $fields[] = [
                'id' => $field['id'],
                'name' => $name,
                'type' => $field['type'],
                'config' => [
                    'label' => ['de-DE' => $field['de'], 'en-GB' => $field['en']],
                    'helpText' => ['de-DE' => $field['deHelp'], 'en-GB' => $field['enHelp']],
                    'componentName' => 'sw-field',
                    'customFieldType' => $field['type'] === CustomFieldTypes::BOOL ? 'switch' : 'text',
                    'type' => $field['type'] === CustomFieldTypes::BOOL ? 'switch' : 'text',
                    'customFieldPosition' => $field['position'],
                ],
            ];
        }

        $repository->upsert([[
            'id' => self::SET_ID,
            'name' => 'nova_upload',
            'config' => ['label' => ['de-DE' => 'Datei-Upload (Nova Upload)', 'en-GB' => 'File upload (Nova Upload)']],
            'relations' => [['id' => self::SET_RELATION_ID, 'entityName' => 'product']],
            'customFields' => $fields,
        ]], $context);
    }
}
