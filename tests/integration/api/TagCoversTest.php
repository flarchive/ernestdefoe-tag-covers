<?php

namespace Ernestdefoe\TagCovers\Tests\integration\api;

use Ernestdefoe\TagCovers\CoverStore;
use Ernestdefoe\TagCovers\TagCover;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

class TagCoversTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-tag-covers');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Tag::class => [
                ['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'parent_id' => null],
                ['id' => 2, 'name' => 'Second', 'slug' => 'second', 'position' => 1, 'parent_id' => null],
                ['id' => 3, 'name' => 'Staff', 'slug' => 'staff-only-room', 'position' => 2, 'parent_id' => null, 'is_restricted' => true],
            ],
        ]);
    }

    /** The page map is cached outside the test's transaction: never let it reach the next test. */
    protected function tearDown(): void
    {
        $this->app?->getContainer()->make(Cache::class)->forget(CoverStore::IMAGERY_CACHE_KEY);

        parent::tearDown();
    }

    /** A real PNG: the store decodes and re-encodes the upload. */
    private function png(string $field): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tag-cover').'.png';

        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);

        return [$field => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'cover.png', 'image/png')];
    }

    private function upload(string $kind, int $tagId, ?int $actor, ?array $files = null): array
    {
        $field = $kind === 'logos' ? 'logo' : 'cover';

        $response = $this->send(
            $this->asActor($this->request('POST', "/api/tag-$kind/$tagId"), $actor)
                ->withUploadedFiles($files ?? $this->png($field))
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function remove(string $kind, int $tagId, ?int $actor): int
    {
        return $this->send(
            $this->asActor($this->request('DELETE', "/api/tag-$kind/$tagId"), $actor)
        )->getStatusCode();
    }

    /** A guest's write needs a session's CSRF token, or it never reaches the controller. */
    private function asActor(ServerRequestInterface $request, ?int $actor): ServerRequestInterface
    {
        if ($actor) {
            return $this->requestAsUser($request, $actor);
        }

        // The API's own session hands out the token, without compiling the
        // forum's assets the way a GET / would.
        $initial = $this->send($this->request('GET', '/api'));

        return $this->requestWithCookiesFrom($request->withHeader('X-CSRF-Token', $initial->getHeaderLine('X-CSRF-Token')), $initial);
    }

    /** @return array<string, array{coverUrl: ?string, logoUrl: ?string}> */
    private function tagImagery(int $actor = 1): array
    {
        $response = $this->send($this->request('GET', '/api/tags', ['authenticatedAs' => $actor]));
        $this->assertSame(200, $response->getStatusCode());

        $out = [];
        foreach (json_decode((string) $response->getBody(), true)['data'] as $tag) {
            $out[$tag['id']] = [
                'coverUrl' => $tag['attributes']['coverUrl'],
                'logoUrl' => $tag['attributes']['logoUrl'],
            ];
        }

        return $out;
    }

    #[Test]
    public function only_an_admin_can_upload_or_remove_an_image()
    {
        foreach (['covers', 'logos'] as $kind) {
            [$status] = $this->upload($kind, 1, null);
            $this->assertSame(403, $status, "A guest cannot upload to tag-$kind");

            [$status] = $this->upload($kind, 1, 2);
            $this->assertSame(403, $status, "A member cannot upload to tag-$kind");

            $this->assertSame(403, $this->remove($kind, 1, null), "A guest cannot remove from tag-$kind");
            $this->assertSame(403, $this->remove($kind, 1, 2), "A member cannot remove from tag-$kind");
        }

        $this->assertSame(0, TagCover::query()->count());
    }

    #[Test]
    public function an_admin_upload_is_stored_and_serialized_on_the_tag()
    {
        [$status, $body] = $this->upload('covers', 1, 1);

        $this->assertSame(200, $status);
        $this->assertStringEndsWith('.webp', $body['coverUrl']);
        $this->assertStringContainsString('tag-cover-1-', $body['coverUrl']);

        $tags = $this->tagImagery();
        $this->assertSame($body['coverUrl'], $tags['1']['coverUrl']);
        $this->assertNull($tags['1']['logoUrl']);
        $this->assertNull($tags['2']['coverUrl']);
    }

    #[Test]
    public function a_logo_goes_through_its_own_route_and_column()
    {
        [, $cover] = $this->upload('covers', 1, 1);
        [$status, $logo] = $this->upload('logos', 1, 1);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('tag-logo-1-', $logo['logoUrl']);

        $tags = $this->tagImagery();
        $this->assertSame($logo['logoUrl'], $tags['1']['logoUrl']);
        $this->assertSame($cover['coverUrl'], $tags['1']['coverUrl'], 'Uploading a logo leaves the cover alone');
    }

    #[Test]
    public function a_missing_tag_or_a_bad_file_is_refused()
    {
        [$status] = $this->upload('covers', 999, 1);
        $this->assertSame(404, $status);

        [$status] = $this->upload('covers', 1, 1, []);
        $this->assertSame(422, $status, 'No file');

        $path = tempnam(sys_get_temp_dir(), 'tag-cover');
        file_put_contents($path, '<?php echo "not an image";');
        [$status] = $this->upload('covers', 1, 1, ['cover' => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'cover.png', 'image/png')]);
        $this->assertSame(422, $status, 'The sniffed type is trusted, not the client\'s');

        $this->assertSame(0, TagCover::query()->count());
    }

    #[Test]
    public function removing_both_images_removes_the_row()
    {
        $this->upload('covers', 1, 1);
        $this->upload('logos', 1, 1);

        $this->assertSame(200, $this->remove('covers', 1, 1));
        $tags = $this->tagImagery();
        $this->assertNull($tags['1']['coverUrl']);
        $this->assertNotNull($tags['1']['logoUrl'], 'Removing the cover keeps the logo');
        $this->assertSame(1, TagCover::query()->count());

        $this->assertSame(200, $this->remove('logos', 1, 1));
        $this->assertSame(0, TagCover::query()->count());
    }

    #[Test]
    public function listing_many_tags_with_images_is_not_a_query_per_tag()
    {
        $tags = $covers = [];
        for ($id = 10; $id < 20; $id++) {
            $tags[] = ['id' => $id, 'name' => "Tag $id", 'slug' => "tag-$id", 'position' => $id, 'parent_id' => null];
            $covers[] = ['tag_id' => $id, 'path' => "tag-cover-$id-x.webp", 'logo_path' => "tag-logo-$id-x.webp"];
        }
        $this->prepareDatabase([Tag::class => $tags, TagCover::class => $covers]);

        // Counted directly: flarum/testing's detector only warns when a
        // per-tag lookup repeats a few tags, and this must be exactly one.
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $listed = $this->tagImagery();
        $lookups = array_filter($db->getQueryLog(), fn ($q) => str_contains($q['query'], 'tag_covers'));
        $this->assertCount(1, $lookups, 'One query for every tag\'s imagery, not one per tag');

        $this->assertCount(10, array_filter($listed, fn ($t) => $t['coverUrl'] && $t['logoUrl']));
        $this->assertStringEndsWith('/tag-logo-15-x.webp', $listed['15']['logoUrl']);
    }

    /** The `tagCoverImagery` map from the page's JSON payload, or null when absent. */
    private function pageImagery(?int $actor): ?array
    {
        $html = (string) $this->send($this->request('GET', '/', $actor ? ['authenticatedAs' => $actor] : []))->getBody();

        preg_match('#<script id="flarum-json-payload" type="application/json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m, 'The page has a JSON payload');

        return json_decode($m[1], true)['tagCoverImagery'] ?? null;
    }

    #[Test]
    public function the_page_payload_carries_imagery_only_for_tags_the_viewer_can_see()
    {
        // Debug mode recompiles the forum's JS with source maps on every page
        // render, which outgrows PHP's default memory limit by the third.
        $this->config('debug', false);

        $this->assertNull($this->pageImagery(null), 'Absent, not empty, when nothing is set');

        $this->upload('covers', 1, 1);
        $this->upload('logos', 3, 1);

        $guest = $this->pageImagery(null);
        $this->assertSame(['open'], array_keys($guest), 'A restricted tag\'s slug and imagery must not reach a guest');
        $this->assertStringContainsString('tag-cover-1-', $guest['open']['cover']);
        $this->assertArrayNotHasKey('logo', $guest['open']);

        $admin = $this->pageImagery(1);
        $this->assertEqualsCanonicalizing(['open', 'staff-only-room'], array_keys($admin));
        $this->assertStringContainsString('tag-logo-3-', $admin['staff-only-room']['logo']);
    }

    #[Test]
    public function every_cover_change_refreshes_the_cached_page_map()
    {
        $this->config('debug', false);
        $cache = $this->app()->getContainer()->make(Cache::class);

        $this->assertNull($this->pageImagery(1));
        $this->assertSame([], $cache->get(CoverStore::IMAGERY_CACHE_KEY), 'An empty board is cached too');

        $this->upload('covers', 1, 1);
        $this->assertNull($cache->get(CoverStore::IMAGERY_CACHE_KEY), 'An upload forgets the map');
        $first = $this->pageImagery(1)['open']['cover'];

        $this->upload('covers', 1, 1);
        $this->assertNotSame($first, $this->pageImagery(1)['open']['cover'], 'A replace shows the new file');

        $this->assertSame(200, $this->remove('covers', 1, 1));
        $this->assertNull($this->pageImagery(1), 'A removal takes the tag out of the map');
    }

    #[Test]
    public function a_page_with_the_map_cached_reads_no_imagery_table_and_joins_nothing()
    {
        $this->config('debug', false);
        $this->upload('covers', 1, 1);
        $this->pageImagery(1);

        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $imagery = $this->pageImagery(1);
        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();

        $this->assertArrayHasKey('open', $imagery);
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q, 'tag_covers')), 'Served from the cache');
        $this->assertEmpty(array_filter($queries, fn ($q) => stripos($q, ' join ') !== false && str_contains($q, 'tags')), 'No JOIN onto the tags');
    }

    #[Test]
    public function replacing_an_image_deletes_only_the_file_it_replaces()
    {
        $disk = $this->app()->getContainer()->make(Factory::class)->disk('flarum-assets');

        [, $first] = $this->upload('covers', 1, 1);
        [, $logo] = $this->upload('logos', 1, 1);
        [, $other] = $this->upload('covers', 2, 1);
        $firstName = basename($first['coverUrl']);

        // A file of this tag's shape that no row records: not ours to judge,
        // and finding it would need a listing of the whole disk.
        $disk->put('tag-cover-1-0000beef.webp', 'x');

        [$status, $second] = $this->upload('covers', 1, 1);
        $this->assertSame(200, $status);
        $this->assertNotSame($first['coverUrl'], $second['coverUrl']);

        $this->assertFalse($disk->exists($firstName), 'The replaced cover is deleted');
        $this->assertTrue($disk->exists(basename($second['coverUrl'])));
        $this->assertTrue($disk->exists(basename($logo['logoUrl'])), 'The logo is a different kind');
        $this->assertTrue($disk->exists(basename($other['coverUrl'])), 'Another tag\'s cover stays');
        $this->assertSame(basename($second['coverUrl']), TagCover::query()->find(1)->path);

        $this->assertSame(200, $this->remove('covers', 1, 1));
        $this->assertFalse($disk->exists(basename($second['coverUrl'])), 'Removing deletes the recorded file');
        $this->assertTrue($disk->exists(basename($logo['logoUrl'])));

        $disk->delete('tag-cover-1-0000beef.webp');
    }

    #[Test]
    public function an_upload_with_no_file_behind_its_stream_is_still_sniffed()
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $stream = (new StreamFactory())->createStream($png);
        [$status, $body] = $this->upload('covers', 1, 1, ['cover' => new UploadedFile($stream, strlen($png), UPLOAD_ERR_OK, 'cover.png', 'image/png')]);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('tag-cover-1-', $body['coverUrl']);
    }

    #[Test]
    public function an_image_declaring_too_many_pixels_is_refused_before_it_is_decoded()
    {
        // A few bytes of PNG whose header claims 6000x6000 (36 megapixels).
        // Decoding it would allocate ~144MB; the header alone is read.
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 6000, 6000, 8, 2, 0, 0, 0)).$chunk('IDAT', '').$chunk('IEND', '');

        $stream = (new StreamFactory())->createStream($png);
        [$status, $body] = $this->upload('covers', 1, 1, ['cover' => new UploadedFile($stream, strlen($png), UPLOAD_ERR_OK, 'cover.png', 'image/png')]);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('too_many_pixels', json_encode($body));
        $this->assertSame(0, TagCover::query()->count());
    }

    #[Test]
    public function a_change_while_another_holds_the_lock_is_refused_at_once()
    {
        $this->app();

        $store = $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->getStore();
        $this->assertInstanceOf(\Illuminate\Contracts\Cache\LockProvider::class, $store);

        $lock = $store->lock('tag-covers:1:cover', 30);
        $this->assertTrue($lock->get());

        try {
            $started = microtime(true);
            [$status, $body] = $this->upload('covers', 1, 1);

            $this->assertSame(422, $status);
            $this->assertStringContainsString('api.busy', json_encode($body));
            $this->assertLessThan(1.0, microtime(true) - $started, 'Refused without waiting');
            $this->assertSame(0, TagCover::query()->count());

            // The logo is a different lock: it goes through.
            [$status] = $this->upload('logos', 1, 1);
            $this->assertSame(200, $status);
        } finally {
            $lock->release();
        }

        [$status] = $this->upload('covers', 1, 1);
        $this->assertSame(200, $status, 'Free again once the lock is released');
    }
}
