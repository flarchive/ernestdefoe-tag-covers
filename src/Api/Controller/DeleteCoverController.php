<?php

namespace Ernestdefoe\TagCovers\Api\Controller;

use Ernestdefoe\TagCovers\CoverStore;
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

        $tagId = (int) Arr::get($request->getAttribute('routeParameters', []), 'id', 0);

        $this->covers->remove($tagId, $kind);

        return new JsonResponse([($logo ? 'logoUrl' : 'coverUrl') => null]);
    }
}
