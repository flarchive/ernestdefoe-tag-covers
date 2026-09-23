<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A second image per tag: the logo.
 *
 * A column on the existing row rather than a table of its own — both images
 * belong to the same tag and share its lifetime, so one row per tag keeps the
 * single foreign key doing the cleanup and keeps the field resource down to
 * one query for the whole page.
 *
 * 🚨 `path` becomes nullable in the same step. It was NOT NULL because a row
 * only ever existed to hold a cover; a tag that wants a logo and no cover is
 * now perfectly ordinary, and without this it could not be stored.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('tag_covers')) {
            return;
        }

        if (! $schema->hasColumn('tag_covers', 'logo_path')) {
            $schema->table('tag_covers', function (Blueprint $table) {
                $table->string('logo_path')->nullable()->after('path');
            });
        }

        $schema->table('tag_covers', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('tag_covers')) {
            return;
        }

        if ($schema->hasColumn('tag_covers', 'logo_path')) {
            $schema->table('tag_covers', function (Blueprint $table) {
                $table->dropColumn('logo_path');
            });
        }
    },
];
