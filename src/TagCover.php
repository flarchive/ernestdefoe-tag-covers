<?php

namespace Ernestdefoe\TagCovers;

use Flarum\Database\AbstractModel;

/**
 * @property int $tag_id
 * @property string|null $path
 * @property string|null $logo_path
 * @property \Carbon\Carbon|null $updated_at
 */
class TagCover extends AbstractModel
{
    protected $table = 'tag_covers';

    protected $primaryKey = 'tag_id';

    public $incrementing = false;

    public $timestamps = false;

    // Flarum's AbstractModel guards every attribute, so updateOrCreate()
    // throws MassAssignmentException without this.
    protected $fillable = ['tag_id', 'path', 'logo_path', 'updated_at'];

    protected $casts = [
        'tag_id' => 'integer',
        'updated_at' => 'datetime',
    ];
}
