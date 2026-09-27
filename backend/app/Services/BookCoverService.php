<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Book cover images.
 *
 * One cover per TITLE, not per physical copy — the picture describes the
 * edition, and every copy of that edition looks the same.
 *
 * Stored on the `public` disk with a generated filename. The uploaded
 * filename is never used or trusted: it is attacker-controlled and can carry
 * path separators, double extensions or a null byte. Only the resulting
 * relative path is written to the database; the bytes stay on disk.
 */
class BookCoverService
{
    public const DISK = 'public';
    public const DIRECTORY = 'library/covers';
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Store a new cover for a book, replacing any previous one.
     *
     * @return string the stored relative path
     */
    public function store(Book $book, UploadedFile $file): string
    {
        // Validation also happens in the form request; repeated here because
        // this service is the last line before the filesystem.
        $mime = $file->getMimeType();

        if (! isset(self::ALLOWED_MIME[$mime])) {
            throw new \InvalidArgumentException('Cover must be a JPEG, PNG or WebP image.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Cover must be 5 MB or smaller.');
        }

        $extension = self::ALLOWED_MIME[$mime];
        $filename = 'book-'.$book->book_id.'-'.Str::random(16).'.'.$extension;

        $path = $file->storeAs(self::DIRECTORY, $filename, self::DISK);

        $previous = $book->cover_image_path;

        $book->cover_image_path = $path;
        $book->save();

        // Only after the new path is committed, so a failed save cannot leave
        // the book pointing at a file that has already been deleted.
        $this->deleteManagedFile($previous);

        return $path;
    }

    /** Remove a book's cover, leaving the frontend to show its placeholder. */
    public function remove(Book $book): void
    {
        $previous = $book->cover_image_path;

        $book->cover_image_path = null;
        $book->save();

        $this->deleteManagedFile($previous);
    }

    /**
     * Delete a file only if it is one of ours.
     *
     * The path comes out of the database, but a bad row (or a future bug)
     * must never be able to point this at something outside the covers
     * directory. Anything that does not match is left alone.
     */
    protected function deleteManagedFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (! Str::startsWith($path, self::DIRECTORY.'/')) {
            return;
        }

        if (Str::contains($path, ['..', "\0"])) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
