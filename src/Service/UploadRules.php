<?php declare(strict_types=1);

namespace Nova\Upload\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Erlaubte Dateitypen und Grenzen (Plugin-Konfiguration pro Verkaufskanal).
 */
class UploadRules
{
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_PRODUCT = 'product';

    /** Bilder, Grafik, Dokumente, Archive, 3D/CAD – keine ausführbaren oder Web-Dateien */
    public const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'svg',
        'ai', 'eps', 'psd', 'cdr',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'csv', 'txt', 'rtf',
        'zip',
        'stl', '3mf', 'obj', 'step', 'stp', 'iges', 'igs', 'amf', 'ply', 'wrl', 'x3d', 'dxf', 'dwg',
    ];

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isContactActive(?string $salesChannelId): bool
    {
        return $this->config->getBool('NovaUpload.config.contactActive', $salesChannelId);
    }

    /**
     * @return array{maxFiles: int, maxMb: int, maxTotalMb: int}
     */
    public function limits(string $source, ?string $salesChannelId): array
    {
        $prefix = $source === self::SOURCE_PRODUCT ? 'product' : 'contact';
        $maxFiles = max(1, $this->config->getInt('NovaUpload.config.' . $prefix . 'MaxFiles', $salesChannelId) ?: ($prefix === 'product' ? 3 : 5));
        $maxMb = max(1, $this->config->getInt('NovaUpload.config.' . $prefix . 'MaxMb', $salesChannelId) ?: ($prefix === 'product' ? 20 : 10));
        $maxTotalMb = max($maxMb, $this->config->getInt('NovaUpload.config.' . $prefix . 'MaxTotalMb', $salesChannelId) ?: ($prefix === 'product' ? 50 : 25));

        return ['maxFiles' => $maxFiles, 'maxMb' => $maxMb, 'maxTotalMb' => $maxTotalMb];
    }

    /**
     * Prüft Anzahl, Typ und Größe. Gibt die Fehlermeldung zurück oder null, wenn alles passt.
     *
     * @param list<UploadedFile> $files
     */
    public function validate(array $files, string $source, ?string $salesChannelId): ?string
    {
        if ($files === []) {
            return null;
        }

        $limits = $this->limits($source, $salesChannelId);
        $mb = 1024 * 1024;

        if (\count($files) > $limits['maxFiles']) {
            return $this->translator->trans('novaUpload.errorCount', ['%count%' => $limits['maxFiles']]);
        }

        $total = 0;
        foreach ($files as $file) {
            $name = self::safeName($file->getClientOriginalName());

            if (!$file->isValid()) {
                return $this->translator->trans('novaUpload.errorFailed', ['%name%' => $name]);
            }

            if (!\in_array(mb_strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true)) {
                return $this->translator->trans('novaUpload.errorType', ['%name%' => $name]);
            }

            $size = (int) $file->getSize();
            if ($size > $limits['maxMb'] * $mb) {
                return $this->translator->trans('novaUpload.errorSize', ['%name%' => $name, '%size%' => $limits['maxMb']]);
            }

            $total += $size;
        }

        if ($total > $limits['maxTotalMb'] * $mb) {
            return $this->translator->trans('novaUpload.errorTotal', ['%size%' => $limits['maxTotalMb']]);
        }

        return null;
    }

    public function trans(string $id): string
    {
        return $this->translator->trans($id);
    }

    /**
     * Hochgeladene Dateien eines Feldes (auch verschachtelt, z. B. novaUpload[<productId>][]).
     *
     * @return list<UploadedFile>
     */
    public static function files(Request $request, string $field, ?string $key = null): array
    {
        $raw = $request->files->all()[$field] ?? null;
        if ($key !== null) {
            $raw = \is_array($raw) ? ($raw[$key] ?? null) : null;
        }
        $raw = \is_array($raw) ? $raw : [$raw];

        return array_values(array_filter($raw, static fn ($file): bool => $file instanceof UploadedFile));
    }

    /** Dateiname ohne Pfad und Sonderzeichen – sicher für Mail, Download und Anzeige */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\w.\- ]+/u', '_', $name);

        return mb_substr(trim($name, ' .') ?: 'datei', 0, 120);
    }
}
