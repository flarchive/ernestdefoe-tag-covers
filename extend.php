<?php

use Ernestdefoe\TagCovers\Api\Controller\DeleteCoverController;
use Ernestdefoe\TagCovers\Api\Controller\UploadCoverController;
use Ernestdefoe\TagCovers\Api\Resource\TagCoverField;
use Ernestdefoe\TagCovers\Frontend\AddTagImagery;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->content(AddTagImagery::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->post('/tag-covers/{id}', 'tag-covers.upload', UploadCoverController::class)
        ->delete('/tag-covers/{id}', 'tag-covers.delete', DeleteCoverController::class)
        ->post('/tag-logos/{id}', 'tag-logos.upload', UploadCoverController::class)
        ->delete('/tag-logos/{id}', 'tag-logos.delete', DeleteCoverController::class),

    // `coverUrl` and `logoUrl` on every tag, so anything that renders a tag
    // can use them.
    (new Extend\ApiResource(\Flarum\Tags\Api\Resource\TagResource::class))
        ->fields(TagCoverField::class),
];
