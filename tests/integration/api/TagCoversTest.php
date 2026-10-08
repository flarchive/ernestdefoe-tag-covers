<?php

namespace Ernestdefoe\TagCovers\Tests\integration\api;

use Ernestdefoe\TagCovers\TagCover;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\UploadedFile;
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

    /** A real PNG: the store decodes and re-encodes the upload. */
    private function png(string $field): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tag-cover').'.png';

        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);

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
}
