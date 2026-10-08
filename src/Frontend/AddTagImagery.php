<?php

namespace Ernestdefoe\TagCovers\Frontend;

use Ernestdefoe\TagCovers\CoverStore;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
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
    public function __construct(
        protected CoverStore $store
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        /*
         * 🚨 One joined query, not hydrated Tag models. This runs on EVERY page render,
         * so it must not be the reason a forum with a lot of tags gets slower —
         * and the only two things a consumer needs are the slug it keys its
         * rule on and the URL.
         */
        // Only tags the viewer can see: a restricted tag's slug and imagery
        // are not for a guest's page source.
        $rows = Tag::query()
            ->whereVisibleTo(RequestUtil::getActor($request))
            ->join('tag_covers', 'tags.id', '=', 'tag_covers.tag_id')
            ->where(function ($q) {
                $q->whereNotNull('tag_covers.path')->orWhereNotNull('tag_covers.logo_path');
            })
            ->toBase()
            ->get(['tags.slug', 'tag_covers.path', 'tag_covers.logo_path']);

        $map = [];

        foreach ($rows as $row) {
            $entry = [];

            if ($row->path) {
                $entry['cover'] = $this->store->url($row->path);
            }

            if ($row->logo_path) {
                $entry['logo'] = $this->store->url($row->logo_path);
            }

            if ($entry !== []) {
                $map[(string) $row->slug] = $entry;
            }
        }

        // Absent rather than empty, so a consumer can tell "this extension
        // published nothing" from "this board has no imagery set".
        if ($map !== []) {
            $document->payload['tagCoverImagery'] = $map;
        }
    }
}
