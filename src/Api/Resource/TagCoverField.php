<?php

namespace Ernestdefoe\TagCovers\Api\Resource;

use Ernestdefoe\TagCovers\CoverStore;
use Ernestdefoe\TagCovers\TagCover;
use Flarum\Api\Schema;

/**
 * Adds `coverUrl` and `logoUrl` to every serialized tag.
 *
 * The whole map is loaded once per request rather than a query per tag —
 * the category index serializes every tag on the forum at once, so a
 * per-tag lookup would be a query per card. Both columns come back in that
 * one query for the same reason.
 */
class TagCoverField
{
    /** @var array<int, array{path: ?string, logo_path: ?string}>|null */
    protected ?array $map = null;

    public function __construct(protected CoverStore $covers)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Str::make('coverUrl')
                ->nullable()
                ->get(fn ($tag) => $this->url($tag->id, 'path')),

            Schema\Str::make('logoUrl')
                ->nullable()
                ->get(fn ($tag) => $this->url($tag->id, 'logo_path')),
        ];
    }

    protected function url(int $tagId, string $column): ?string
    {
        $this->map ??= TagCover::query()
            ->get(['tag_id', 'path', 'logo_path'])
            ->keyBy('tag_id')
            ->map(fn ($row) => ['path' => $row->path, 'logo_path' => $row->logo_path])
            ->all();

        $path = $this->map[$tagId][$column] ?? null;

        return $path ? $this->covers->url($path) : null;
    }
}
