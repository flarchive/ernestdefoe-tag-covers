<?php

namespace Ernestdefoe\TagCovers;

use Carbon\Carbon;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Reads and writes the image files themselves.
 *
 * Files live on the `flarum-assets` disk, the same place core keeps the logo
 * and favicon, so they are served by the web server and survive a cache
 * clear. Everything is re-encoded to webp at a bounded size — an admin
 * uploading a 6MB PNG straight from an image generator should not become a
 * 6MB download for every visitor.
 *
 * 🚨 Two KINDS of image share this store: the `cover` a tag is drawn on and
 * the `logo` drawn over it. They differ only in how big they are allowed to
 * be — a logo renders at chip size, so 1200px of it is 1200px nobody sees —
 * and in the filename prefix, which is what keeps each kind's cleanup from
 * sweeping up the other.
 */
class CoverStore
{
    public const QUALITY = 82;

    /** The shape of every name putContents() gives a file. */
    private const NAME = '/^tag-(cover|logo)-\d+-[0-9a-f]{8}\.webp$/D';

    /** kind => the widest it is ever rendered, near enough. */
    public const MAX_WIDTH = [
        'cover' => 1200,
        'logo' => 256,
    ];

    protected Cloud $disk;

    public function __construct(
        Factory $filesystem,
        protected ImageManager $images,
    ) {
        $disk = $filesystem->disk('flarum-assets');

        // url() is on Cloud, not the base contract; core types this disk the
        // same way (Frontend\Assets, ExtensionManager).
        if (! $disk instanceof Cloud) {
            throw new \RuntimeException('The flarum-assets disk cannot produce public URLs.');
        }

        $this->disk = $disk;
    }

    /** @return string the stored filename */
    public function put(int $tagId, UploadedFileInterface $file, string $kind = 'cover'): string
    {
        return $this->putContents($tagId, (string) $file->getStream(), $kind);
    }

    /**
     * Store one image for a tag: write the file, point the tag's row at it,
     * then delete the file it replaces.
     *
     * Takes bytes rather than an upload so seeding a board from an existing
     * site (the importer) goes through the same three steps.
     *
     * 🚨 Old files are found by the path the ROW records, never by listing
     * the disk: on S3 a listing is a paginated LIST of everything else on
     * flarum-assets, on every upload. The order keeps that path honest — the
     * row moves before the old file goes, and if the row cannot be saved the
     * new file is removed again, so no file is ever left that no row names.
     *
     * @return string the stored filename
     */
    public function putContents(int $tagId, string $bytes, string $kind = 'cover'): string
    {
        $encoded = (string) $this->images
            ->read($bytes)
            ->scaleDown(width: self::MAX_WIDTH[$kind] ?? 1200)
            ->toWebp(self::QUALITY);

        $name = $this->prefix($tagId, $kind).substr(bin2hex(random_bytes(4)), 0, 8).'.webp';
        $column = self::column($kind);
        $old = TagCover::query()->whereKey($tagId)->value($column);

        $this->disk->put($name, $encoded);

        try {
            TagCover::query()->updateOrCreate(['tag_id' => $tagId], [$column => $name, 'updated_at' => Carbon::now()]);
        } catch (\Throwable $e) {
            $this->disk->delete($name);

            throw $e;
        }

        $this->deleteFile($tagId, $kind, $old);

        return $name;
    }

    /** Delete the file of one kind that a tag's row records. The row is the caller's to update. */
    public function forget(int $tagId, string $kind = 'cover'): void
    {
        $this->deleteFile($tagId, $kind, TagCover::query()->whereKey($tagId)->value(self::column($kind)));
    }

    /** The tag_covers column that records each kind. */
    public static function column(string $kind): string
    {
        return $kind === 'logo' ? 'logo_path' : 'path';
    }

    /**
     * Only ever a name this store wrote for THIS tag and kind: a row edited by
     * hand cannot point the delete at core's logo or another tag's image.
     */
    protected function deleteFile(int $tagId, string $kind, mixed $path): void
    {
        if (is_string($path) && preg_match(self::NAME, $path) && str_starts_with($path, $this->prefix($tagId, $kind))) {
            $this->disk->delete($path);
        }
    }

    /**
     * Purging the extension deletes every cover and logo it stored. Called by
     * the covers table's down(), so disabling alone keeps them.
     *
     * The flarum-assets disk is core's, so it is there while the extension is
     * disabled, and a configured storage driver still applies. Only names of
     * the shape putContents() writes are deleted: core's logo and favicon, and
     * anything else on that disk, stay.
     */
    public static function purge(Container $container): void
    {
        $disk = $container->make(Factory::class)->disk('flarum-assets');

        $disk->delete(preg_grep(self::NAME, $disk->files()));
    }

    public function url(string $path): string
    {
        return $this->disk->url($path);
    }

    /**
     * 🚨 `tag-cover-` must NOT be a prefix of `tag-logo-`, or one kind's
     * prefix sweep deletes the other's files. Distinct words, not a suffix on
     * a shared stem.
     */
    protected function prefix(int $tagId, string $kind): string
    {
        return ($kind === 'logo' ? 'tag-logo-' : 'tag-cover-').$tagId.'-';
    }
}
