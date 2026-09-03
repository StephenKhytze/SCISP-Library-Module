<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Prevent duplicate seeding
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        BookCopy::truncate();
        Book::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Create 4 Test Users corresponding to each role.
        // We will mock their authentication in the frontend via headers like 'student:1'
        $student = User::create(['user_id' => 1, 'username' => 'student', 'password' => bcrypt('password'), 'role' => 'student', 'status' => 'active', 'total_fines' => 0]);
        $faculty = User::create(['user_id' => 2, 'username' => 'faculty', 'password' => bcrypt('password'), 'role' => 'faculty', 'status' => 'active', 'total_fines' => 0]);
        $admin = User::create(['user_id' => 3, 'username' => 'admin', 'password' => bcrypt('password'), 'role' => 'administrator', 'status' => 'active', 'total_fines' => 0]);
        $superadmin = User::create(['user_id' => 4, 'username' => 'superadmin', 'password' => bcrypt('password'), 'role' => 'administrator', 'status' => 'active', 'total_fines' => 0]);

        // Create 3 Test Books
        $book1 = Book::create([
            'book_title' => 'The Pragmatic Programmer',
            'author' => 'David Thomas, Andrew Hunt',
            'category' => 'Computer Science',
            'isbn' => '978-0135957059',
            'physical_location' => 'Shelf A1',
            'total_copies' => 2,
        ]);

        $book2 = Book::create([
            'book_title' => 'Clean Code: A Handbook of Agile Software Craftsmanship',
            'author' => 'Robert C. Martin',
            'category' => 'Software Engineering',
            'isbn' => '978-0132350884',
            'physical_location' => 'Shelf A2',
            'total_copies' => 1,
        ]);

        $book3 = Book::create([
            'book_title' => 'Introduction to Algorithms',
            'author' => 'Thomas H. Cormen',
            'category' => 'Computer Science',
            'isbn' => '978-0262033848',
            'physical_location' => 'Shelf B1',
            'total_copies' => 2,
        ]);

        // Create Book Copies for Book 1
        BookCopy::create(['book_id' => $book1->book_id, 'condition' => 'new', 'availability_status' => 'available']);
        BookCopy::create(['book_id' => $book1->book_id, 'condition' => 'good', 'availability_status' => 'available']);

        // Create Book Copy for Book 2
        BookCopy::create(['book_id' => $book2->book_id, 'condition' => 'fair', 'availability_status' => 'available']);

        // Create Book Copies for Book 3
        BookCopy::create(['book_id' => $book3->book_id, 'condition' => 'new', 'availability_status' => 'available']);
        BookCopy::create(['book_id' => $book3->book_id, 'condition' => 'good', 'availability_status' => 'available']);
    }
}
