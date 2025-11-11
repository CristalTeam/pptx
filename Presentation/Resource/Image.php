<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\Config\OptimizationConfig;

class Image extends GenericResource
{
    /**
     * @var int|null Taille originale de l'image
     */
    private $originalSize;

    /**
     * @var int|null Taille compressée de l'image
     */
    private $compressedSize;

    /**
     * @var OptimizationConfig|null
     */
    private $config;

    /**
     * Définit la configuration d'optimisation
     *
     * @param OptimizationConfig $config
     */
    public function setOptimizationConfig(OptimizationConfig $config): void
    {
        $this->config = $config;
    }

    /**
     * Définit le contenu de l'image avec optimisation optionnelle
     *
     * @param string $content
     */
    public function setContent(string $content): void
    {
        $this->originalSize = strlen($content);
        
        // Appliquer les optimisations si configurées
        if ($this->config && $this->config->isEnabled('image_compression')) {
            $content = $this->optimizeImage($content);
        }
        
        $this->compressedSize = strlen($content);
        
        parent::setContent($content);
    }

    /**
     * Optimise une image (compression + redimensionnement)
     *
     * @param string $content Contenu de l'image
     * @return string Contenu optimisé
     */
    private function optimizeImage(string $content): string
    {
        $imageType = $this->detectImageType($content);
        
        if (!$imageType) {
            return $content; // Type non supporté, pas d'optimisation
        }
        
        $image = @imagecreatefromstring($content);
        
        if ($image === false) {
            return $content; // Image corrompue, pas d'optimisation
        }
        
        // Redimensionnement si nécessaire
        if ($this->config->isEnabled('image_compression')) {
            $image = $this->resizeIfNeeded($image);
        }
        
        // Compression selon le type
        $optimized = $this->compressImage($image, $imageType);
        
        imagedestroy($image);
        
        return $optimized !== false ? $optimized : $content;
    }

    /**
     * Détecte le type d'image à partir du contenu
     *
     * @param string $content Contenu de l'image
     * @return string|null Type d'image (jpeg, png, gif, etc.) ou null si non supporté
     */
    public function detectImageType(string $content): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);
        
        $typeMap = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        
        return $typeMap[$mimeType] ?? null;
    }

    /**
     * Redimensionne l'image si elle dépasse les dimensions max
     *
     * @param resource $image Image GD
     * @return resource Image redimensionnée ou originale
     */
    private function resizeIfNeeded($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $maxWidth = $this->config->get('max_image_width');
        $maxHeight = $this->config->get('max_image_height');
        
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $image; // Pas besoin de redimensionner
        }
        
        // Calculer les nouvelles dimensions en préservant le ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Préserver la transparence pour PNG et GIF
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        
        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );
        
        imagedestroy($image);
        
        return $resized;
    }

    /**
     * Compresse une image selon son type
     *
     * @param resource $image Image GD
     * @param string $type Type d'image (jpeg, png, etc.)
     * @return string|false Contenu compressé ou false en cas d'erreur
     */
    private function compressImage($image, string $type)
    {
        ob_start();
        
        $result = false;
        
        switch ($type) {
            case 'jpeg':
                $quality = $this->config->get('image_quality');
                $result = imagejpeg($image, null, $quality);
                break;
                
            case 'png':
                // PNG: niveau de compression 0-9 (9 = max compression)
                $level = (int) (9 - ($this->config->get('image_quality') / 100 * 9));
                $result = imagepng($image, null, $level);
                break;
                
            case 'gif':
                $result = imagegif($image, null);
                break;
                
            case 'webp':
                if (function_exists('imagewebp')) {
                    $quality = $this->config->get('image_quality');
                    $result = imagewebp($image, null, $quality);
                }
                break;
        }
        
        $content = ob_get_clean();
        
        return $result ? $content : false;
    }

    /**
     * Compresse un JPEG
     *
     * @param string $content Contenu JPEG
     * @param int $quality Qualité (1-100)
     * @return string Contenu compressé
     */
    public function compressJpeg(string $content, int $quality): string
    {
        $image = @imagecreatefromstring($content);
        
        if ($image === false) {
            return $content;
        }
        
        ob_start();
        imagejpeg($image, null, $quality);
        $compressed = ob_get_clean();
        
        imagedestroy($image);
        
        return $compressed !== false ? $compressed : $content;
    }

    /**
     * Compresse un PNG
     *
     * @param string $content Contenu PNG
     * @param int $level Niveau de compression (0-9)
     * @return string Contenu compressé
     */
    public function compressPng(string $content, int $level): string
    {
        $image = @imagecreatefromstring($content);
        
        if ($image === false) {
            return $content;
        }
        
        // Préserver la transparence
        imagealphablending($image, false);
        imagesavealpha($image, true);
        
        ob_start();
        imagepng($image, null, $level);
        $compressed = ob_get_clean();
        
        imagedestroy($image);
        
        return $compressed !== false ? $compressed : $content;
    }

    /**
     * Obtient les dimensions d'une image
     *
     * @param string $content Contenu de l'image
     * @return array|null ['width' => int, 'height' => int] ou null si erreur
     */
    public function getDimensions(string $content): ?array
    {
        $image = @imagecreatefromstring($content);
        
        if ($image === false) {
            return null;
        }
        
        $dimensions = [
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];
        
        imagedestroy($image);
        
        return $dimensions;
    }

    /**
     * Retourne la taille originale de l'image
     *
     * @return int|null
     */
    public function getOriginalSize(): ?int
    {
        return $this->originalSize;
    }

    /**
     * Retourne la taille compressée de l'image
     *
     * @return int|null
     */
    public function getCompressedSize(): ?int
    {
        return $this->compressedSize;
    }

    /**
     * Retourne le ratio de compression
     *
     * @return float|null
     */
    public function getCompressionRatio(): ?float
    {
        if ($this->originalSize === null || $this->compressedSize === null || $this->originalSize === 0) {
            return null;
        }
        
        return $this->compressedSize / $this->originalSize;
    }
}