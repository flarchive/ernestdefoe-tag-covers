<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A cover image per tag.
 *
 * A table rather than a settings blob: this is per-tag data whose lifetime
 * belongs to the tag, so the foreign key clears it up when a tag is deleted
 * instead of leaving an orphaned entry behind.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('tag_covers')) {
            return;
        }

        $schema->create('tag_covers', function (Blueprint $table) {
            $table->unsignedInteger('tag_id')->primary();
            $table->string('path');
            $table->dateTime('updated_at')->nullable();

            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('tag_covers');
    },
];
