<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Video resource class for handling video files in PPTX.
 *
 * @see ECMA-376 Part 1, Section 20.1.3.6 - videoFile
 */
class Video extends GenericResource
{
    /**
     * Supported video formats with their MIME types.
     */
    public const SUPPORTED_FORMATS = [
        'video/mp4' => 'mp4',
        'video/x-ms-wmv' => 'wmv',
        'video/avi' => 'avi',
        'video/x-msvideo' => 'avi',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'm4v',
        'video/webm' => 'webm',
        'video/mpeg' => 'mpg',
        'video/x-mpeg' => 'mpg',
        'video/x-flv' => 'flv',
        'video/3gpp' => '3gp',
    ];

    /**
     * File extension to MIME type mapping.
     */
    public const EXTENSION_MIME_MAP = [
        'mp4' => 'video/mp4',
        'wmv' => 'video/x-ms-wmv',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        'webm' => 'video/webm',
        'mpg' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'flv' => 'video/x-flv',
        '3gp' => 'video/3gpp',
    ];

    /**
     * Get the video file extension.
     */
    public function getExtension(): string
    {
        return self::SUPPORTED_FORMATS[$this->contentType] ?? pathinfo($this->target, PATHINFO_EXTENSION);
    }

    /**
     * Check if the video format is supported by PowerPoint.
     */
    public function isSupported(): bool
    {
        return isset(self::SUPPORTED_FORMATS[$this->contentType]);
    }

    /**
     * Get the file size in bytes.
     */
    public function getSize(): int
    {
        return strlen($this->getContent());
    }

    /**
     * Get the file size formatted as human-readable string.
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->getSize();
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get MIME type from file extension.
     */
    public static function getMimeFromExtension(string $extension): ?string
    {
        $ext = strtolower(ltrim($extension, '.'));

        return self::EXTENSION_MIME_MAP[$ext] ?? null;
    }

    /**
     * Check if the given MIME type is a video type.
     */
    public static function isVideoMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    /**
     * Check if the video is embeddable in PowerPoint.
     * Some formats may only be supported as linked files.
     */
    public function isEmbeddable(): bool
    {
        $embeddableFormats = ['video/mp4', 'video/x-ms-wmv', 'video/x-msvideo', 'video/quicktime'];

        return in_array($this->contentType, $embeddableFormats, true);
    }

    /**
     * Get video metadata (placeholder - would require video parsing library).
     *
     * @return array{duration: int|null, width: int|null, height: int|null, frameRate: float|null, codec: string|null}
     */
    public function getMetadata(): array
    {
        // Video metadata extraction requires external libraries like FFMpeg
        // This is a placeholder that returns null values
        return [
            'duration' => null,
            'width' => null,
            'height' => null,
            'frameRate' => null,
            'codec' => null,
        ];
    }

    /**
     * Get video thumbnail if available in the presentation.
     * This would typically be stored as a related image resource.
     */
    public function getThumbnail(): ?Image
    {
        // Thumbnails are typically stored separately in the PPTX structure
        // This would need to check for related image resources
        return null;
    }
}