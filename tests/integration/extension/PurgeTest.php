<?php

namespace Ernestdefoe\TagCovers\Tests\integration\extension;

use Flarum\Extension\ExtensionManager;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\Test;

class PurgeTest extends TestCase
{
    private const OURS = ['tag-cover-1-0123abcd.webp', 'tag-logo-12-89abcdef.webp'];

    private const NOT_OURS = ['logo-abc123.png', 'tag-cover-notes.txt'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-tag-covers');

        $disk = $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');
        foreach ([...self::OURS, ...self::NOT_OURS] as $file) {
            $disk->put($file, 'x');
        }

        $this->root = $this->tmpDir().'/public/assets';
    }

    protected function tearDown(): void
    {
        foreach ([...self::OURS, ...self::NOT_OURS] as $file) {
            @unlink($this->root.'/'.$file);
        }

        parent::tearDown();
    }

    #[Test]
    public function purging_deletes_the_covers_and_logos_it_stored_and_nothing_else()
    {
        // The covers table's real down(), run on a throwaway schema: dropping
        // the test database's table would end its transaction on MariaDB.
        $migration = require __DIR__.'/../../../migrations/2026_08_29_000000_create_tag_covers_table.php';
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $migration['down']($capsule->getConnection()->getSchemaBuilder());

        foreach (self::OURS as $file) {
            $this->assertFileDoesNotExist($this->root.'/'.$file);
        }
        foreach (self::NOT_OURS as $file) {
            $this->assertFileExists($this->root.'/'.$file, 'A file the extension did not write stays');
        }
    }

    #[Test]
    public function disabling_keeps_the_images()
    {
        $this->app()->getContainer()->make(ExtensionManager::class)->disable('ernestdefoe-tag-covers');

        foreach (self::OURS as $file) {
            $this->assertFileExists($this->root.'/'.$file);
        }
    }
}
