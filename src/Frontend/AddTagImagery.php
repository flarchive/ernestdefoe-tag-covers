<?php

namespace Ernestdefoe\TagCovers\Frontend;

use Ernestdefoe\TagCovers\CoverStore;
use Ernestdefoe\TagCovers\TagCover;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Publish every tag's imagery in the page payload.
 *
 * 🚨 The stylesheet this extension injects is built from `app.store.all('tags')`,
 * and that store holds whatever the page happened to load — which on a Flarum
 * forum is the PRIMARY tags and nothing else. A board with sixteen conferences
 * and a hundred and thirty-five team tags therefore published sixteen rules,
 * and every team's crest was missing everywhere except a page that had already
 * loaded that team for some other reason. It looked like the crests had never
 * been set; they had been, and nothing could see them.
 *
 * The payload is a flat map instead, so a consumer's rules do not depend on
 * what else the page happened to ask for.
 */
class AddTagImagery
{
    /** A backstop only: CoverStore forgets the map on every change it makes. */
    public const TTL = 600;

    public function __construct(
        protected CoverStore $store,
        protected Cache $cache,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $imagery = $this->imagery();

        // Most pages of most forums end here, with no query at all.
        if ($imagery === []) {
            return;
        }

        /*
         * 🚨 The cache holds every tag's imagery, whoever asked first, so who may
         * see what is decided HERE, per request, never inside the cached value:
         * a restricted tag's slug and imagery are not for a guest's page source.
         * The slug is read live with it, so a renamed tag needs no invalidation.
         */
        $slugs = Tag::query()
            ->whereVisibleTo(RequestUtil::getActor($request))
            ->whereIn('id', array_keys($imagery))
            ->pluck('slug', 'id');

        $map = [];

        foreach ($slugs as $id => $slug) {
            $entry = [];

            foreach ($imagery[$id] as $kind => $path) {
                $entry[$kind] = $this->store->url($path);
            }

            $map[(string) $slug] = $entry;
        }

        // Absent rather than empty, so a consumer can tell "this extension
        // published nothing" from "this board has no imagery set".
        if ($map !== []) {
            $document->payload['tagCoverImagery'] = $map;
        }
    }

    /**
     * tag id => ['cover' => path, 'logo' => path], for every tag with an image.
     *
     * 🚨 One small table, read once per TTL or change rather than joined to
     * the tags on every page render.
     *
     * @return array<int, array<string, string>>
     */
    protected function imagery(): array
    {
        return $this->cache->remember(CoverStore::IMAGERY_CACHE_KEY, self::TTL, function () {
            $imagery = [];

            foreach (TagCover::query()->toBase()->get(['tag_id', 'path', 'logo_path']) as $row) {
                $entry = array_filter(['cover' => $row->path, 'logo' => $row->logo_path]);

                if ($entry !== []) {
                    $imagery[(int) $row->tag_id] = $entry;
                }
            }

            return $imagery;
        });
    }
}
