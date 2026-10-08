<?php

namespace Ernestdefoe\TagCovers\Api\Controller;

use Ernestdefoe\TagCovers\CoverStore;
use Ernestdefoe\TagCovers\TagCover;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/tag-covers/{id} and /api/tag-logos/{id} — attach an image to a tag.
 *
 * One class for both kinds: the validation, the size ceiling and the sniffed
 * type are the same question about the same upload, and a second copy of them
 * is a second place for the answer to drift.
 */
class UploadCoverController implements RequestHandlerInterface
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ALLOWED = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(protected CoverStore $covers)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // 🚨 The ROUTE says which image this is, never the request body — a
        // caller who could name the kind could write a logo through the cover
        // endpoint and past the other one's size ceiling.
        $logo = str_contains($request->getUri()->getPath(), '/tag-logos/');
        $kind = $logo ? 'logo' : 'cover';
        $field = $kind;
        $column = $logo ? 'logo_path' : 'path';

        $tagId = (int) Arr::get($request->getQueryParams(), 'id', 0);
        $tag = Tag::query()->find($tagId);

        if (! $tag) {
            throw new ModelNotFoundException();
        }

        $file = Arr::get($request->getUploadedFiles(), $field);

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => 'No image was uploaded.']);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException([$field => 'That image is larger than 8MB.']);
        }

        // Trust the sniffed type, not the client-supplied one.
        $type = (string) @mime_content_type($file->getStream()->getMetadata('uri'));

        if (! in_array($type, self::ALLOWED, true)) {
            throw new ValidationException([$field => 'That file is not a PNG, JPEG, WebP or GIF image.']);
        }

        $path = $this->covers->put($tagId, $file, $kind);

        TagCover::query()->updateOrCreate(
            ['tag_id' => $tagId],
            [$column => $path, 'updated_at' => date('Y-m-d H:i:s')]
        );

        return new JsonResponse([($logo ? 'logoUrl' : 'coverUrl') => $this->covers->url($path)]);
    }
}
