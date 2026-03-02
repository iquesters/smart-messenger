<?php

namespace Iquesters\SmartMessenger\Support;

class MessageContentHelper
{
    public static function parseTextPayload(?string $content): array
    {
        $text = (string) ($content ?? '');
        $imageUrl = null;
        $isStructured = false;

        if (is_string($content) && $content !== '') {
            $decoded = json_decode($content, true);

            if (is_array($decoded) && (array_key_exists('caption', $decoded) || array_key_exists('image_url', $decoded))) {
                $text = (string) ($decoded['caption'] ?? '');
                $imageUrl = $decoded['image_url'] ?? null;
                $isStructured = true;
            }
        }

        return [
            'text' => $text,
            'image_url' => $imageUrl,
            'is_structured' => $isStructured,
        ];
    }

    public static function formatText(?string $text, bool $preserveLineBreaks = true): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $prepared = $preserveLineBreaks
            ? (string) $text
            : trim((string) preg_replace('/\s+/', ' ', $text));

        $formatted = e($prepared);
        $formatted = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $formatted);
        $formatted = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', '<strong>$1</strong>', $formatted);
        $formatted = preg_replace('/(?<!_)_(?!\s)(.+?)(?<!\s)_(?!_)/s', '<em>$1</em>', $formatted);
        $formatted = preg_replace(
            '/(https?:\/\/[^\s<]+)/',
            '<a href="$1" target="_blank" class="text-primary text-decoration-underline">$1</a>',
            $formatted
        );

        return $preserveLineBreaks ? nl2br($formatted) : $formatted;
    }

    public static function previewData(?string $content, ?string $messageType = 'text'): array
    {
        $payload = self::parseTextPayload($content);

        return [
            'text' => self::formatText($payload['text'], false),
            'icon' => self::resolvePreviewIcon($messageType, $payload),
        ];
    }

    private static function resolvePreviewIcon(?string $messageType, array $payload): string
    {
        switch ($messageType) {
            case 'image':
                return 'fa-regular fa-image';

            case 'audio':
                return 'fa-solid fa-microphone-lines';

            case 'video':
                return 'fa-regular fa-circle-play';

            case 'document':
                return 'fa-regular fa-file-lines';

            case 'text':
                return !empty($payload['image_url']) ? 'fa-regular fa-image' : '';

            default:
                return '';
        }
    }
}
