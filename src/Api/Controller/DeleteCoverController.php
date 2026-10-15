<?php

namespace Ernestdefoe\TagCovers\Api\Controller;

use Ernestdefoe\TagCovers\CoverStore;
use Ernestdefoe\TagCovers\TagCover;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/tag-covers/{id} or /api/tag-logos/{id} — remove one image, file and all. */
class DeleteCoverController implements RequestHandlerInterface
{
    public function __construct(protected CoverStore $covers)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $logo = str_contains($request->getUri()->getPath(), '/tag-logos/');
        $kind = $logo ? 'logo' : 'cover';
        $column = $logo ? 'logo_path' : 'path';

        $tagId = (int) Arr::get($request->getQueryParams(), 'id', 0);

        // Delete the file first: a row pointing at a file that is gone renders
        // a broken image, which is worse than no image at all.
        $this->covers->forget($tagId, $kind);

        $row = TagCover::query()->find($tagId);

        if ($row) {
            $row->{$column} = null;

            // 🚨 Clear the ROW once neither image is left. Leaving an empty row
            // behind means the field resource carries it, and a later "has this
            // tag got a cover?" written against row existence answers yes.
            if ($row->path === null && $row->logo_path === null) {
                $row->delete();
            } else {
                $row->save();
            }
        }

        return new JsonResponse([($logo ? 'logoUrl' : 'coverUrl') => null]);
    }
}
