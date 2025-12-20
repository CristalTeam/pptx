<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Audio resource class for handling audio files in PPTX.
 *
 * @see ECMA-376 Part 1, Section 20.1.3.1 - audioFile
 */
class Audio extends GenericResource
{
    /**
     * Supported audio formats with their MIME types.
     */
    public const SUPPORTED_FORMATS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/x-ms-wma' => 'wma',
    ];

    /**
     * File extension to MIME type mapping.
     */
    public const EXTENSION_MIME_MAP = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'webm' => 'audio/webm',
        'wma' => 'audio/x-ms-wma',
    ];

    /**
     * Get the audio file extension.
     */
    public function getExtension(): string
    {
        return self::SUPPORTED_FORMATS[$this->contentType] ?? pathinfo($this->target, PATHINFO_EXTENSION);
    }

    /**
     * Check if the audio format is supported by PowerPoint.
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
     * Check if the given MIME type is an audio type.
     */
    public static function isAudioMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/');
    }

    /**
     * Get audio metadata (placeholder - would require audio parsing library).
     *
     * @return array{duration: int|null, bitrate: int|null, channels: int|null, sampleRate: int|null}
     */
    public function getMetadata(): array
    {
        // Audio metadata extraction requires external libraries like getID3
        // This is a placeholder that returns null values
        return [
            'duration' => null,
            'bitrate' => null,
            'channels' => null,
            'sampleRate' => null,
        ];
    }
}