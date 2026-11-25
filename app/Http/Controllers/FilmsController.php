<?php

namespace App\Http\Controllers;

use App\Models\Films;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Inertia;

class FilmsController extends Controller
{
    /**
     * Largest website image accepted, in kilobytes. The same limit as a film's
     * banner, so nobody has to remember two numbers.
     */
    public const MAX_IMAGE_KB = EventsController::MAX_IMAGE_KB;

    /** Where website images live, under public/storage. */
    private const IMAGE_DIR = 'website_images';

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Films', [
            'films' => Films::withCount('events')->orderBy('name')->get()->map(fn (Films $film) => [
                'id' => $film->id,
                'name' => $film->name,
                'image_url' => $this->publicAsset($film->image),
                'events_count' => $film->events_count,
            ]),
            'maxImageKb' => self::MAX_IMAGE_KB,
        ]);
    }

    /**
     * Show the brand analytics report page (data is loaded via API on the frontend).
     */
    public function report(Films $film)
    {
        return Inertia::render('BrandReport', [
            'film' => $film->only(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:films,name',
            'country' => 'nullable|string|max:255',
            'image' => $this->imageRule(),
        ], $this->imageMessages());

        $data = [
            'name' => $request->name,
            'country' => $request->country,
        ];

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImage($request->file('image'));
        }

        Films::create($data);

        return redirect()->route('films.index')->with('success', 'Film created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Films $film)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:films,name,'.$film->id,
            'country' => 'nullable|string|max:255',
            'image' => $this->imageRule(),
            'remove_image' => 'nullable|boolean',
        ], $this->imageMessages());

        $data = ['name' => $request->name];

        // The form does not send a country, and saving a name should not blank
        // one that is already there.
        if ($request->has('country')) {
            $data['country'] = $request->country;
        }

        // A new picture replaces the old one; "remove" goes back to the default.
        // Either way the old file is left on disk — nothing is deleted.
        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $data['image'] = null;
        }

        $film->update($data);

        return redirect()->route('films.index')->with('success', 'Film updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Films $film)
    {
        if (!$film) {
            return response()->json(['error' => 'Film not found.'], 404);
        }

        // Check if film has associated events
        if ($film->events()->count() > 0) {
            return redirect()->route('films.index')->with('error', 'Cannot delete this brand because it has connected events.');
        }

        $film->delete();

        return redirect()->route('films.index')->with('success', 'Film deleted successfully.');
    }

    private function imageRule(): string
    {
        return 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:'.self::MAX_IMAGE_KB;
    }

    /** Plain-English reasons an image was refused. */
    private function imageMessages(): array
    {
        $mb = rtrim(rtrim(number_format(self::MAX_IMAGE_KB / 1024, 1), '0'), '.');

        return [
            'image.max' => "That image is too large. The limit is {$mb} MB — try resizing it or exporting at a lower quality.",
            'image.image' => 'That is not an image file. Use a JPG, PNG, GIF or WebP.',
            'image.mimes' => 'That image must be a JPG, PNG, GIF or WebP.',
            'image.uploaded' => "That image could not be uploaded. It may be over the {$mb} MB limit.",
        ];
    }

    /**
     * Save an uploaded image beside the film banners and return its stored path.
     */
    private function storeImage(UploadedFile $file): string
    {
        $destination = public_path('storage/'.self::IMAGE_DIR);
        if (! file_exists($destination)) {
            mkdir($destination, 0755, true);
        }

        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'website';
        $filename = time().'_'.$name.'.'.$file->extension();
        $file->move($destination, $filename);

        return self::IMAGE_DIR.'/'.$filename;
    }

    /**
     * Public URL for a stored image, or null when there is none or the file is
     * missing — the page falls back to the default picture in both cases.
     */
    private function publicAsset(?string $path): ?string
    {
        if (blank($path) || ! file_exists(public_path('storage/'.$path))) {
            return null;
        }

        return url('storage/'.implode('/', array_map('rawurlencode', explode('/', $path))));
    }
}
