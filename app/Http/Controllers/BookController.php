<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookController extends Controller
{
   private $path = 'data/books.json';

    private function readData()
    {
        $json = Storage::get($this->path);
        return json_decode($json, true) ?? [];
    }

    private function writeData($data)
    {
        Storage::put($this->path, json_encode($data, JSON_PRETTY_PRINT));
    }

    // 🟢 Retrieve books with filters and pagination
    public function index(Request $request)
    {
        if (!Storage::exists($this->path)) {
           return response()->json(['debug_path' => Storage::path($this->path)]);
        }
        $books = collect($this->readData());
        // print_r($books);
        // Filters
        if ($request->has('id')) {
            $ids = explode(',', $request->query('id'));
            $books = $books->whereIn('id', array_map('intval', $ids));
        }

        if ($request->has('languages')) {
            $langs = array_map('trim', explode(',', strtolower($request->query('languages'))));
            $books = $books->filter(function ($b) use ($langs) {
                $bookLangs = $b['languages'] ?? [];
                if (!is_array($bookLangs)) {
                    // if it's a single string, wrap it
                    $bookLangs = [$bookLangs];
                }
                // normalize lower-case strings only
                $bookLangs = array_filter(array_map(function ($l) {
                    return is_string($l) ? strtolower($l) : null;
                }, $bookLangs));

                foreach ($bookLangs as $bl) {
                    if (in_array($bl, $langs)) return true;
                }
                return false;
            });
        }

        // mime_type filter (guard against missing or non-string mime types)
        if ($request->has('mime_type')) {
            $mimes = array_map('trim', explode(',', strtolower($request->query('mime_type'))));
            $books = $books->filter(function ($b) use ($mimes) {
                foreach ($b['formats'] ?? [] as $f) {
                    $mt = $f['mime_type'] ?? '';
                    if (!is_string($mt)) continue;
                    if (in_array(strtolower($mt), $mimes)) return true;
                }
                return false;
            });
        }

        // Topic filter (your existing approach was OK, just extra-guard)
        if ($request->has('topic')) {
            $topics = array_map('trim', explode(',', strtolower($request->query('topic'))));
            $books = $books->filter(function ($b) use ($topics) {
                foreach (['subjects', 'bookshelves'] as $field) {
                    if (empty($b[$field]) || !is_array($b[$field])) continue;
                    foreach ($b[$field] as $val) {
                        if (!is_string($val)) continue;
                        foreach ($topics as $topic) {
                            if (Str::contains(strtolower($val), $topic)) return true;
                        }
                    }
                }
                return false;
            });
        }

        $titleTerms = [];
        $authorTerms = [];

        if ($request->has('q')) {
            $q = trim($request->query('q', ''));
            if ($q !== '') {
                // allow multiple comma-separated tokens in q as well
                $titleTerms = array_map('trim', explode(',', strtolower($q)));
                $authorTerms = $titleTerms;
            }
        } else {
            if ($request->has('title')) {
                $titleTerms = array_map('trim', explode(',', strtolower($request->query('title'))));
            }
            if ($request->has('author')) {
                $authorTerms = array_map('trim', explode(',', strtolower($request->query('author'))));
            }
        }

        if (!empty($titleTerms) || !empty($authorTerms)) {
            $books = $books->filter(function ($b) use ($titleTerms, $authorTerms) {
                // prepare title string (book may store title as string or array)
                $titleField = $b['title'] ?? '';
                if (is_array($titleField)) {
                    $titleField = implode(' ', array_map(function($t){ return is_string($t) ? $t : ''; }, $titleField));
                }
                if (!is_string($titleField)) $titleField = '';

                // prepare author string (book may have array or single author)
                $authorField = $b['author'] ?? '';
                if (is_array($authorField)) {
                    $authorField = implode(' ', array_map(function($a){ return is_string($a) ? $a : ''; }, $authorField));
                }
                if (!is_string($authorField)) $authorField = '';

                $lowerTitle = strtolower($titleField);
                $lowerAuthor = strtolower($authorField);

                // If titleTerms provided, match any of them in title
                if (!empty($titleTerms)) {
                    foreach ($titleTerms as $t) {
                        if ($t === '') continue;
                        if (Str::contains($lowerTitle, $t)) return true;
                    }
                }

                // If authorTerms provided, match any of them in author
                if (!empty($authorTerms)) {
                    foreach ($authorTerms as $a) {
                        if ($a === '') continue;
                        if (Str::contains($lowerAuthor, $a)) return true;
                    }
                }

                // no match
                return false;
            });
        }

        // Sort by downloads (descending)
        $books = $books->sortByDesc('downloads')->values();

        // Pagination (25 per page)
        $page = max(1, intval($request->query('page', 1)));
        $perPage = 25;
        $total = $books->count();
        $paged = $books->forPage($page, $perPage)->values();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'books' => $paged
        ]);
    }

    // 🟢 Add a new book
    public function store(Request $request)
    {
        $books = $this->readData();
        $newBook = $request->all();
        $newBook['id'] = collect($books)->max('id') + 1;
        $books[] = $newBook;
        $this->writeData($books);
        return response()->json(['message' => 'Book added', 'book' => $newBook], 201);
    }

    // 🟢 Update existing book
    public function update(Request $request, $id)
    {
        $books = collect($this->readData());
        $index = $books->search(fn($b) => $b['id'] == $id);

        if ($index === false) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        $book = array_merge($books[$index], $request->all());
        $books[$index] = $book;
        $this->writeData($books->values()->toArray());

        return response()->json(['message' => 'Book updated', 'book' => $book]);
    }

    // 🟢 Delete book
    public function destroy($id)
    {
        $books = collect($this->readData());
        $filtered = $books->reject(fn($b) => $b['id'] == $id)->values();
        $this->writeData($filtered->toArray());

        return response()->json(['message' => 'Book deleted']);
    }
}
