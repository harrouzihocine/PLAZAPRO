<?php

declare(strict_types=1);

namespace App\Core\Media;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Canonical MIME for an uploaded file. Trusts libmagic first, but real Office
 * files routinely defeat it: depending on how the producer laid out the
 * archive, a genuine .pptx/.docx/.xlsx can sniff as bare "application/zip"
 * (and legacy .ppt/.doc/.xls as a bare OLE container). When libmagic reports
 * only an opaque container, this looks inside it the way the big upload
 * pipelines do — `[Content_Types].xml` names the OOXML family, the ODF
 * `mimetype` entry names itself — and returns the canonical type. The client
 * extension is only ever consulted to pick the family of a verified legacy
 * OLE container, never trusted on its own.
 */
class UploadMimeDetector
{
    /** libmagic verdicts that mean "some zip/OLE container" and nothing more. */
    private const OPAQUE_ZIP = [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument', // truncated OOXML guess
    ];

    private const OPAQUE_OLE = [
        'application/x-ole-storage',
        'application/CDFV2',
        'application/CDFV2-unknown',
        'application/vnd.ms-office',
    ];

    /** Read caps: sniffing must never inflate an attacker's zip into memory. */
    private const CONTENT_TYPES_MAX_BYTES = 262_144;

    public function detect(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        if (in_array($mime, self::OPAQUE_ZIP, true)) {
            return $this->sniffZip((string) $file->getRealPath()) ?? $mime;
        }

        if (in_array($mime, self::OPAQUE_OLE, true)) {
            return $this->legacyOleFamily($file) ?? $mime;
        }

        return $mime;
    }

    /** Preferred stored extension for a canonical mime (null when unknown). */
    public function extensionFor(string $mime): ?string
    {
        return MimeTypes::getDefault()->getExtensions($mime)[0] ?? null;
    }

    /**
     * OOXML zips carry their family in [Content_Types].xml; ODF zips carry
     * their exact mime in the `mimetype` entry. Anything else stays opaque.
     */
    private function sniffZip(string $path): ?string
    {
        $zip = new \ZipArchive;
        if ($path === '' || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $odf = $zip->getFromName('mimetype', 100);
            if (is_string($odf) && str_starts_with(trim($odf), 'application/vnd.oasis.opendocument.')) {
                return trim($odf);
            }

            $types = $zip->getFromName('[Content_Types].xml', self::CONTENT_TYPES_MAX_BYTES);
            if (! is_string($types)) {
                return null;
            }

            return match (true) {
                str_contains($types, 'presentationml') => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                str_contains($types, 'wordprocessingml') => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                str_contains($types, 'spreadsheetml') => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => null,
            };
        } finally {
            $zip->close();
        }
    }

    /**
     * The bytes are a verified OLE compound file (legacy Office container);
     * libmagic just couldn't tell which application. The extension only picks
     * the family — an .exe renamed to .ppt is not OLE and never reaches here.
     */
    private function legacyOleFamily(UploadedFile $file): ?string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'ppt', 'pps', 'pot' => 'application/vnd.ms-powerpoint',
            'doc', 'dot' => 'application/msword',
            'xls', 'xlt' => 'application/vnd.ms-excel',
            default => null,
        };
    }
}
