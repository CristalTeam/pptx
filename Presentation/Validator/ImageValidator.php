<?php

namespace Cristal\Presentation\Validator;

use Cristal\Presentation\Config\OptimizationConfig;

class ImageValidator
{
    /**
     * @var OptimizationConfig
     */
    private $config;

    /**
     * @var array Erreurs de validation
     */
    private $errors = [];

    /**
     * @var array Formats supportés
     */
    private const SUPPORTED_FORMATS = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    /**
     * ImageValidator constructor.
     *
     * @param OptimizationConfig|null $config
     */
    public function __construct(?OptimizationConfig $config = null)
    {
        $this->config = $config;
    }

    /**
     * Valide une image
     *
     * @param string $content Contenu de l'image
     * @return bool True si valide
     */
    public function validate(string $content): bool
    {
        $this->errors = [];

        // Validation de la taille
        if (!$this->validateSize($content)) {
            return false;
        }

        // Validation du type MIME
        if (!$this->validateMimeType($content)) {
            return false;
        }

        // Validation de l'intégrité
        if (!$this->validateIntegrity($content)) {
            return false;
        }

        // Validation des dimensions si activée
        if ($this->config && $this->config->isEnabled('validate_images')) {
            if (!$this->validateDimensions($content)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valide la taille du fichier
     *
     * @param string $content
     * @return bool
     */
    public function validateSize(string $content): bool
    {
        $size = strlen($content);

        if ($size === 0) {
            $this->errors[] = 'Image vide';
            return false;
        }

        $maxSize = $this->config ? $this->config->get('max_image_size') : 10 * 1024 * 1024;

        if ($size > $maxSize) {
            $this->errors[] = sprintf(
                'Image trop grande: %s (max: %s)',
                $this->formatBytes($size),
                $this->formatBytes($maxSize)
            );
            return false;
        }

        return true;
    }

    /**
     * Valide le type MIME
     *
     * @param string $content
     * @return bool
     */
    public function validateMimeType(string $content): bool
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if (!in_array($mimeType, self::SUPPORTED_FORMATS, true)) {
            $this->errors[] = sprintf(
                'Format non supporté: %s (supportés: %s)',
                $mimeType,
                implode(', ', self::SUPPORTED_FORMATS)
            );
            return false;
        }

        return true;
    }

    /**
     * Valide l'intégrité de l'image
     *
     * @param string $content
     * @return bool
     */
    public function validateIntegrity(string $content): bool
    {
        // Tenter de créer une ressource GD
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            $this->errors[] = 'Image corrompue ou format invalide';
            return false;
        }

        imagedestroy($image);
        return true;
    }

    /**
     * Valide les dimensions de l'image
     *
     * @param string $content
     * @return bool
     */
    public function validateDimensions(string $content): bool
    {
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        imagedestroy($image);

        // Dimensions minimales
        if ($width < 1 || $height < 1) {
            $this->errors[] = 'Dimensions invalides';
            return false;
        }

        // Dimensions maximales si configurées
        if ($this->config) {
            $maxWidth = $this->config->get('max_image_width') * 2; // 2x pour éviter faux positifs
            $maxHeight = $this->config->get('max_image_height') * 2;

            if ($width > $maxWidth || $height > $maxHeight) {
                $this->errors[] = sprintf(
                    'Image trop grande: %dx%d (max recommandé: %dx%d)',
                    $width,
                    $height,
                    $maxWidth,
                    $maxHeight
                );
                // Warning seulement, pas d'échec
            }
        }

        return true;
    }

    /**
     * Vérifie si une image est corrompue
     *
     * @param string $content
     * @return bool True si corrompue
     */
    public function isCorrupted(string $content): bool
    {
        return !$this->validateIntegrity($content);
    }

    /**
     * Retourne les erreurs de validation
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Retourne la dernière erreur
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return end($this->errors) ?: null;
    }

    /**
     * Valide et retourne un rapport
     *
     * @param string $content
     * @return array
     */
    public function validateWithReport(string $content): array
    {
        $isValid = $this->validate($content);

        $report = [
            'valid' => $isValid,
            'errors' => $this->errors,
            'size' => strlen($content),
            'mime_type' => null,
            'dimensions' => null,
        ];

        if ($isValid || !empty($content)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $report['mime_type'] = $finfo->buffer($content);

            $image = @imagecreatefromstring($content);
            if ($image !== false) {
                $report['dimensions'] = [
                    'width' => imagesx($image),
                    'height' => imagesy($image),
                ];
                imagedestroy($image);
            }
        }

        return $report;
    }

    /**
     * Formate une taille en octets
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . $units[$index];
    }

    /**
     * Obtient les formats supportés
     *
     * @return array
     */
    public static function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}