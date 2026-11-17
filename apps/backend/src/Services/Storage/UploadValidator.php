<?php

declare(strict_types=1);

namespace Vistynq\Services\Storage;

use RuntimeException;
use Vistynq\Support\ApiException;

/**
 * Проверка multipart-файла по содержимому (docs/api.md §Вложения, R17).
 *
 * Доверять клиентскому Content-Type и расширению нельзя: mime определяется
 * finfo, расширение — из mime, оригинальное имя остаётся только для показа.
 * Лимиты: image 5 МБ, audio 25 МБ. SVG/HTML не принимаются (XSS).
 */
final class UploadValidator
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    /**
     * @var array<string, array{ext: string, kind: string, limit: int}>
     */
    private const TYPES = [
        'image/png' => ['ext' => 'png', 'kind' => 'image', 'limit' => self::MAX_IMAGE_BYTES],
        'image/jpeg' => ['ext' => 'jpg', 'kind' => 'image', 'limit' => self::MAX_IMAGE_BYTES],
        'image/gif' => ['ext' => 'gif', 'kind' => 'image', 'limit' => self::MAX_IMAGE_BYTES],
        'image/webp' => ['ext' => 'webp', 'kind' => 'image', 'limit' => self::MAX_IMAGE_BYTES],
        'audio/mpeg' => ['ext' => 'mp3', 'kind' => 'audio', 'limit' => self::MAX_AUDIO_BYTES],
        'audio/ogg' => ['ext' => 'ogg', 'kind' => 'audio', 'limit' => self::MAX_AUDIO_BYTES],
        'application/ogg' => ['ext' => 'ogg', 'kind' => 'audio', 'limit' => self::MAX_AUDIO_BYTES],
        'audio/wav' => ['ext' => 'wav', 'kind' => 'audio', 'limit' => self::MAX_AUDIO_BYTES],
        'audio/x-wav' => ['ext' => 'wav', 'kind' => 'audio', 'limit' => self::MAX_AUDIO_BYTES],
    ];

    /**
     * @param array<string, mixed>|null $file $_FILES['file']
     */
    public static function inspect(?array $file, bool $imageOnly = false): UploadedFileData
    {
        if ($file === null) {
            throw self::missingFile();
        }

        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_OK;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw self::missingFile();
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ApiException::make(413, 'PAYLOAD_TOO_LARGE', 'Файл больше допустимого размера');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ApiException::validation(['file' => ['не удалось загрузить файл']]);
        }

        $tmpPath = $file['tmp_name'] ?? null;
        if (!is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            throw ApiException::validation(['file' => ['файл не найден']]);
        }

        $size = filesize($tmpPath);
        if ($size === false || $size <= 0) {
            throw ApiException::validation(['file' => ['пустой файл']]);
        }

        $mime = self::detectMime($tmpPath);
        $type = self::TYPES[$mime] ?? null;
        if ($type === null) {
            throw ApiException::make(415, 'UNSUPPORTED_MEDIA_TYPE', 'Недопустимый тип файла');
        }

        if ($imageOnly && $type['kind'] !== 'image') {
            throw ApiException::make(415, 'UNSUPPORTED_MEDIA_TYPE', 'Аватар должен быть изображением');
        }

        if ($size > $type['limit']) {
            throw ApiException::make(413, 'PAYLOAD_TOO_LARGE', 'Файл больше допустимого размера');
        }

        $width = null;
        $height = null;
        if ($type['kind'] === 'image') {
            $dimensions = @getimagesize($tmpPath);
            if ($dimensions === false) {
                throw ApiException::make(415, 'UNSUPPORTED_MEDIA_TYPE', 'Недопустимый тип файла');
            }
            $width = (int) $dimensions[0];
            $height = (int) $dimensions[1];
        }

        return new UploadedFileData(
            tmpPath: $tmpPath,
            originalName: self::originalName($file['name'] ?? null, $type['ext']),
            mime: $mime,
            extension: $type['ext'],
            kind: $type['kind'],
            size: $size,
            width: $width,
            height: $height,
        );
    }

    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Расширение fileinfo недоступно');
        }

        // finfo_close() deprecated в PHP 8.5: объект освобождается сам.
        $mime = finfo_file($finfo, $path);

        if (!is_string($mime) || $mime === '') {
            throw ApiException::make(415, 'UNSUPPORTED_MEDIA_TYPE', 'Не удалось определить тип файла');
        }

        return strtolower($mime);
    }

    private static function originalName(mixed $name, string $extension): string
    {
        $name = is_string($name) ? basename($name) : '';
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'file.' . $extension;
        }

        return mb_strcut($name, 0, 255, 'UTF-8');
    }

    private static function missingFile(): ApiException
    {
        return ApiException::validation(['file' => ['обязателен файл']]);
    }
}
