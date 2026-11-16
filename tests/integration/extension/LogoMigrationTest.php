<?php

namespace Ernestdefoe\TagCovers\Tests\integration\extension;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LogoMigrationTest extends TestCase
{
    #[Test]
    public function rolling_back_the_logo_column_makes_path_required_again()
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db = $capsule->getConnection();
        $schema = $db->getSchemaBuilder();

        $schema->create('tags', fn (Blueprint $table) => $table->increments('id'));
        $db->table('tags')->insert([['id' => 1], ['id' => 2]]);

        $covers = require __DIR__.'/../../../migrations/2026_08_29_000000_create_tag_covers_table.php';
        $logos = require __DIR__.'/../../../migrations/2026_09_08_000000_add_logo_to_tag_covers.php';
        $covers['up']($schema);
        $logos['up']($schema);

        $db->table('tag_covers')->insert([
            ['tag_id' => 1, 'path' => 'tag-cover-1-0123abcd.webp', 'logo_path' => null],
            ['tag_id' => 2, 'path' => null, 'logo_path' => 'tag-logo-2-0123abcd.webp'],
        ]);

        $logos['down']($schema);

        $this->assertFalse($schema->hasColumn('tag_covers', 'logo_path'));
        $this->assertSame([1], $db->table('tag_covers')->pluck('tag_id')->map(fn ($id) => (int) $id)->all(), 'The logo-only row goes with the logo');

        $this->expectException(QueryException::class);
        $db->table('tag_covers')->insert(['tag_id' => 2, 'path' => null]);
    }
}
