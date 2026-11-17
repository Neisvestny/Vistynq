<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Services\Storage\UploadValidator;
use Vistynq\Support\ApiException;

/**
 * Проверка загрузок по содержимому (R17): клиентский Content-Type и имя
 * игнорируются, лимиты image 5 МБ / audio 25 МБ, SVG/HTML не принимаются.
 */
final class UploadValidatorTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testDetectsPngByContentAndIgnoresClientName(): void
    {
        $data = UploadValidator::inspect($this->upload($this->png(), '../../../evil.php'));

        $this->assertSame('image/png', $data->mime);
        $this->assertSame('png', $data->extension);
        $this->assertSame('image', $data->kind);
        $this->assertSame('evil.php', $data->originalName);
        $this->assertSame(1, $data->width);
        $this->assertSame(1, $data->height);
        $this->assertSame(strlen($this->png()), $data->size);
    }

    public function testDetectsAudioByContent(): void
    {
        $data = UploadValidator::inspect($this->upload($this->mp3()));

        $this->assertSame('audio/mpeg', $data->mime);
        $this->assertSame('mp3', $data->extension);
        $this->assertSame('audio', $data->kind);
        $this->assertNull($data->width);
    }

    public function testAudioBetweenImageAndAudioLimitsIsAccepted(): void
    {
        // Чуть больше 5 МБ: для image это 413, для audio лимит 25 МБ.
        $data = UploadValidator::inspect($this->upload($this->mp3() . str_repeat("\0", 5 * 1024 * 1024)));

        $this->assertSame('audio', $data->kind);
    }

    public function testImageOverFiveMiBIsRejected(): void
    {
        $file = $this->upload($this->png() . str_repeat("\0", UploadValidator::MAX_IMAGE_BYTES));

        $error = $this->expectApi(413, fn () => UploadValidator::inspect($file));

        $this->assertSame('PAYLOAD_TOO_LARGE', $error->errorCode());
    }

    public function testMissingFileIsValidationError(): void
    {
        $error = $this->expectApi(422, fn () => UploadValidator::inspect(null));

        $this->assertSame('VALIDATION_FAILED', $error->errorCode());
        $this->assertArrayHasKey('file', $error->errorDetails() ?? []);
    }

    public function testUploadErrorNoFileIsValidationError(): void
    {
        $this->expectApi(422, fn () => UploadValidator::inspect([
            'name' => 'a.png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]));
    }

    public function testUploadIniSizeErrorIsPayloadTooLarge(): void
    {
        $this->expectApi(413, fn () => UploadValidator::inspect([
            'name' => 'a.mp3',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]));
    }

    public function testUnsupportedContentIsRejectedEvenWithImageName(): void
    {
        $file = $this->upload('<?php echo 1;', 'avatar.png');

        $error = $this->expectApi(415, fn () => UploadValidator::inspect($file));

        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $error->errorCode());
    }

    public function testSvgIsRejected(): void
    {
        $file = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'x.svg');

        $this->expectApi(415, fn () => UploadValidator::inspect($file, imageOnly: true));
    }

    public function testImageOnlyRejectsAudio(): void
    {
        $this->expectApi(415, fn () => UploadValidator::inspect($this->upload($this->mp3()), imageOnly: true));
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectApi(422, fn () => UploadValidator::inspect($this->upload('')));
    }

    public function testFallbackOriginalNameComesFromDetectedType(): void
    {
        $data = UploadValidator::inspect($this->upload($this->png(), '   '));

        $this->assertSame('file.png', $data->originalName);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG_1X1, true);
    }

    private function mp3(): string
    {
        // Валидный sync-фрейм MPEG-1 Layer III: finfo определяет audio/mpeg.
        return "\xFF\xFB\x90\x64\x00\x00\x00\x00";
    }

    /**
     * @return array<string, mixed> $_FILES['file']-подобная структура
     */
    private function upload(string $content, string $name = 'upload.bin'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vistynq-upload-');
        $this->assertNotFalse($path);
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    private function expectApi(int $status, callable $fn): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->httpStatus(), $e->getMessage());

            return $e;
        }

        $this->fail('ожидалась ApiException с HTTP ' . $status);
    }
}
