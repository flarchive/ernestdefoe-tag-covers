<?php

namespace Ernestdefoe\TagCovers\Api\Controller;

use Ernestdefoe\TagCovers\CoverStore;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
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

    public function __construct(
        protected CoverStore $covers,
        protected TranslatorInterface $translator,
    ) {
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

        $tagId = (int) Arr::get($request->getQueryParams(), 'id', 0);
        $tag = Tag::query()->find($tagId);

        if (! $tag) {
            throw new ModelNotFoundException();
        }

        $file = Arr::get($request->getUploadedFiles(), $field);

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => $this->translator->trans('ernestdefoe-tag-covers.api.no_image')]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationException([$field => $this->translator->trans('ernestdefoe-tag-covers.api.too_large')]);
        }

        // Trust the sniffed type, not the client-supplied one. Sniffed from
        // the bytes, so a stream with no file behind it is judged the same.
        $bytes = (string) $file->getStream();
        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($type, self::ALLOWED, true)) {
            throw new ValidationException([$field => $this->translator->trans('ernestdefoe-tag-covers.api.bad_type')]);
        }

        $path = $this->covers->putContents($tagId, $bytes, $kind);

        return new JsonResponse([($logo ? 'logoUrl' : 'coverUrl') => $this->covers->url($path)]);
    }
}
