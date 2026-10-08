<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecureFileUploader
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB
    private const MALICIOUS_PATTERNS = ['<?php', '<?=', '<script', '#!'];

    public function upload(UploadedFile $file, string $folder): string
    {
        $this->validate($file);

        $ext  = strtolower($file->getClientOriginalExtension());
        $path = $folder . '/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $ext;

        Storage::disk('s3')->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private'],
        );

        return $path;
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('File too large. Maximum size is 2 MB.');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Only JPEG, PNG, and WebP images are allowed.');
        }

        $content = file_get_contents($file->getRealPath(), false, null, 0, 512);
        foreach (self::MALICIOUS_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                throw new \InvalidArgumentException('Invalid file content.');
            }
        }
    }
}
