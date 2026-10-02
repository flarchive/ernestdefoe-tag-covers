<?php

namespace Ernestdefoe\TagCovers;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
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

    /** kind => the widest it is ever rendered, near enough. */
    public const MAX_WIDTH = [
        'cover' => 1200,
        'logo'  => 256,
    ];

    protected Filesystem $disk;

    public function __construct(
        Factory $filesystem,
        protected ImageManager $images,
    ) {
        $this->disk = $filesystem->disk('flarum-assets');
    }

    /** @return string the stored filename */
    public function put(int $tagId, UploadedFileInterface $file, string $kind = 'cover'): string
    {
        return $this->putContents($tagId, $file->getStream()->getContents(), $kind);
    }

    /**
     * The same, from bytes already in hand.
     *
     * Seeding a board from an existing site has no uploaded file to pass, and
     * going through a fake UploadedFile just to reach the same three lines is
     * more moving parts than the job needs.
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

        $this->forget($tagId, $kind);
        $this->disk->put($name, $encoded);

        return $name;
    }

    /**
     * Remove every file of one kind belonging to a tag.
     *
     * Deliberately matched by PREFIX rather than by the path recorded in the
     * database. An upload that writes its file and then fails before the row
     * is saved leaves an orphan the recorded path knows nothing about; going
     * by prefix means the next upload or removal sweeps it up.
     */
    public function forget(int $tagId, string $kind = 'cover'): void
    {
        $prefix = $this->prefix($tagId, $kind);

        foreach ($this->disk->files() as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                $this->disk->delete($file);
            }
        }
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
