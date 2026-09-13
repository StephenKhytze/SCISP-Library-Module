<?php

/**
 * Book cover images.
 *
 * One cover per TITLE (the picture describes the edition), stored on the
 * public disk with a generated name. The uploaded filename is never trusted.
 */

use App\Models\Book;
use App\Models\User;
use App\Services\BookCoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A stand-in for an uploaded image.
 *
 * UploadedFile::fake()->image() needs GD compiled with JPEG support, which this
 * container's PHP does not have. A sized fake with an explicit MIME type drives
 * exactly the same validation and storage code.
 */
function covImage(string $name = 'cover.jpg', string $mime = 'image/jpeg', int $kilobytes = 40): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

function covAs(string $role, string $username): array
{
    return ['X-Mock-Role' => $role, 'X-Mock-Username' => $username];
}

function covUser(string $username, string $dbRole, bool $isSuperAdmin = false): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'password',
        'role' => $dbRole,
        'status' => 'active',
        'total_fines' => 0,
    ]);

    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

beforeEach(function () {
    Storage::fake(BookCoverService::DISK);

    $this->admin = covUser('cov_admin', 'administrator');
    $this->superAdmin = covUser('cov_super', 'administrator', true);
    $this->student = covUser('cov_student', 'student');
    $this->faculty = covUser('cov_faculty', 'faculty');

    $this->book = Book::create([
        'book_title' => 'Cover Title',
        'author' => 'Author',
        'category' => 'Computer Science',
        'isbn' => '9780000000001',
        'physical_location' => 'Shelf C1',
        'total_copies' => 0,
    ]);
});

test('CV1: an admin can upload a JPEG cover', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => covImage('shelf-photo.jpg'),
        ])
        ->assertStatus(200)
        ->assertJsonPath('message', 'Cover updated.');

    $path = $this->book->fresh()->cover_image_path;

    expect($path)->toStartWith(BookCoverService::DIRECTORY.'/');
    Storage::disk(BookCoverService::DISK)->assertExists($path);
});

test('CV2: PNG and WebP are accepted too', function () {
    foreach (['png', 'webp'] as $extension) {
        $book = Book::create([
            'book_title' => 'Title '.$extension,
            'author' => 'Author',
            'category' => 'Computer Science',
            'isbn' => '978000000'.rand(1000, 9999),
            'physical_location' => 'Shelf C1',
            'total_copies' => 0,
        ]);

        $this->withHeaders(covAs('Admin', 'cov_admin'))
            ->post("/api/library/books/{$book->book_id}/cover", [
                'cover' => covImage("cover.{$extension}", $extension === 'png' ? 'image/png' : 'image/webp'),
            ])
            ->assertStatus(200);
    }
});

test('CV3: the stored filename is generated, not the uploaded one', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            // A filename nobody should ever write to disk verbatim.
            'cover' => covImage('../../evil name.jpg'),
        ])
        ->assertStatus(200);

    $path = $this->book->fresh()->cover_image_path;

    expect($path)->not->toContain('..');
    expect($path)->not->toContain('evil');
    expect($path)->toMatch('#^library/covers/book-\d+-[A-Za-z0-9]{16}\.jpg$#');
});

test('CV4: a non-image is rejected', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])
        ->assertStatus(422);

    expect($this->book->fresh()->cover_image_path)->toBeNull();
});

test('CV5: an oversized image is rejected', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            // 6 MB, over the 5 MB limit.
            'cover' => UploadedFile::fake()->create('huge.jpg', 6 * 1024, 'image/jpeg'),
        ])
        ->assertStatus(422);

    expect($this->book->fresh()->cover_image_path)->toBeNull();
});

test('CV6: replacing a cover removes the old file', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => covImage('first.jpg'),
        ])->assertStatus(200);

    $first = $this->book->fresh()->cover_image_path;

    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => covImage('second.jpg'),
        ])->assertStatus(200);

    $second = $this->book->fresh()->cover_image_path;

    expect($second)->not->toBe($first);
    Storage::disk(BookCoverService::DISK)->assertMissing($first);
    Storage::disk(BookCoverService::DISK)->assertExists($second);
});

test('CV7: a cover can be removed, leaving the placeholder', function () {
    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => covImage(),
        ])->assertStatus(200);

    $path = $this->book->fresh()->cover_image_path;

    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->deleteJson("/api/library/books/{$this->book->book_id}/cover")
        ->assertStatus(200);

    expect($this->book->fresh()->cover_image_path)->toBeNull();
    Storage::disk(BookCoverService::DISK)->assertMissing($path);
});

test('CV8: a stray path outside the covers directory is never deleted', function () {
    Storage::disk(BookCoverService::DISK)->put('important/keep-me.txt', 'data');

    // A bad row should not become a delete primitive.
    $this->book->forceFill(['cover_image_path' => 'important/keep-me.txt'])->save();

    app(BookCoverService::class)->remove($this->book->fresh());

    Storage::disk(BookCoverService::DISK)->assertExists('important/keep-me.txt');
    expect($this->book->fresh()->cover_image_path)->toBeNull();
});

test('CV9: the catalog exposes a cover URL, or null for the placeholder', function () {
    expect($this->book->fresh()->cover_url)->toBeNull();

    $this->withHeaders(covAs('Admin', 'cov_admin'))
        ->post("/api/library/books/{$this->book->book_id}/cover", [
            'cover' => covImage(),
        ])->assertStatus(200);

    expect($this->book->fresh()->cover_url)->toContain('library/covers/');
});

test('CV10: only librarians may change a cover', function () {
    $image = fn () => covImage();

    $this->withHeaders(covAs('Student', 'cov_student'))
        ->post("/api/library/books/{$this->book->book_id}/cover", ['cover' => $image()])
        ->assertStatus(403);

    $this->withHeaders(covAs('Teacher', 'cov_faculty'))
        ->post("/api/library/books/{$this->book->book_id}/cover", ['cover' => $image()])
        ->assertStatus(403);

    $this->withHeaders(covAs('Student', 'cov_student'))
        ->deleteJson("/api/library/books/{$this->book->book_id}/cover")
        ->assertStatus(403);

    $this->withHeaders(covAs('Super Admin', 'cov_super'))
        ->post("/api/library/books/{$this->book->book_id}/cover", ['cover' => $image()])
        ->assertStatus(200);
});
