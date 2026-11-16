<?php

namespace App\Http\Controllers;

use App\Services\Controls\FocusMediaLimits;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FocusMediaUploadController extends Controller
{
    public function __invoke(Request $request, RolePreviewContext $preview): JsonResponse
    {
        $preview->assertWritable();
        abort_unless(
            $request->user()?->is_active
            && in_array($request->user()->role, ['admin', 'supervisor'], true)
            && (int) $request->query('user') === (int) $request->user()->getKey(),
            403,
        );

        $validated = $request->validate([
            'files' => ['required', 'array', 'size:1'],
            'files.0' => ['required', 'file', 'max:'.FocusMediaLimits::VIDEO_KIB],
        ]);
        $file = $validated['files'][0];
        $mime = $file->getMimeType();
        $isImage = in_array($mime, FocusMediaLimits::IMAGE_MIMES, true);
        if ((! $isImage && ! in_array($mime, FocusMediaLimits::VIDEO_MIMES, true))
            || ($isImage && $file->getSize() > FocusMediaLimits::IMAGE_KIB * 1024)) {
            throw ValidationException::withMessages(['files.0' => 'Поддерживаются фото до 10 МБ и видео до 100 МБ.']);
        }

        $storage = FileUploadConfiguration::storage();
        $path = FileUploadConfiguration::storeTemporaryFile($file, FileUploadConfiguration::disk());
        if (! is_string($path)) {
            throw ValidationException::withMessages(['files.0' => 'Не удалось сохранить временный файл.']);
        }
        $filename = basename($path);
        if (! $storage->exists(FileUploadConfiguration::path($filename))) {
            throw ValidationException::withMessages(['files.0' => 'Не удалось сохранить временный файл.']);
        }

        return response()->json(['paths' => [TemporaryUploadedFile::signPath($filename)]]);
    }
}
