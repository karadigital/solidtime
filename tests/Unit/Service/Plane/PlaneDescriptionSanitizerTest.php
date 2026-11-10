<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Plane;

use App\Service\Plane\PlaneDescriptionSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(PlaneDescriptionSanitizer::class)]
class PlaneDescriptionSanitizerTest extends TestCase
{
    private function sanitize(?string $html): string
    {
        return (new PlaneDescriptionSanitizer)->sanitize($html);
    }

    public function test_keeps_basic_formatting(): void
    {
        $result = $this->sanitize('<p class="editor-paragraph-block" data-id="x">Hi <strong>you</strong></p><ul><li>a</li></ul>');

        $this->assertStringContainsString('<p>Hi <strong>you</strong></p>', $result);
        $this->assertStringContainsString('<li>a</li>', $result);
        $this->assertStringNotContainsString('data-id', $result);
        $this->assertStringNotContainsString('class', $result);
    }

    public function test_removes_scripts_and_event_handlers(): void
    {
        $result = $this->sanitize('<p onclick="x()">a</p><script>alert(1)</script><img src="x" onerror="alert(1)"><iframe src="https://evil"></iframe>');

        foreach (['script', 'onclick', 'onerror', 'iframe', 'alert'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $result);
        }
    }

    public function test_only_allows_http_links_and_opens_them_in_a_new_tab(): void
    {
        $result = $this->sanitize('<a href="javascript:alert(1)">x</a><a href="https://plane.test/a">y</a>');

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/plane\.test\/a"[^>]*>y<\/a>/', $result);
        $this->assertMatchesRegularExpression('/<a [^>]*target="_blank"[^>]*>y<\/a>/', $result);
        $this->assertMatchesRegularExpression('/<a [^>]*rel="noopener noreferrer"[^>]*>y<\/a>/', $result);
    }

    public function test_replaces_images_with_a_placeholder(): void
    {
        $result = $this->sanitize('<image-component src="9b76ba18-c388-403f-81a0-ea4e7145eeff" status="uploaded"></image-component>');

        $this->assertSame('<p><em>[Image – open in Plane to view]</em></p>', $result);
    }

    public function test_replaces_mentions_with_text(): void
    {
        $result = $this->sanitize('<p>Hi <mention-component entity_identifier="u1" entity_name="user_mention"></mention-component></p>');

        // The sanitizer encodes "@" as "&#64;", which browsers render as "@".
        $this->assertSame('<p>Hi &#64;mention</p>', $result);
    }

    public function test_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', $this->sanitize(null));
        $this->assertSame('', $this->sanitize(''));
        $this->assertSame('', $this->sanitize('<p></p>'));
        $this->assertSame('', $this->sanitize('<p class="editor-paragraph-block"></p><p> </p>'));
    }

    public function test_replaces_plain_images_with_a_placeholder_and_drops_remote_media(): void
    {
        $result = $this->sanitize('<p>a</p><img src="https://evil.example/track.gif?u=1"><img srcset="https://evil.example/a.png 1x"><video src="https://evil.example/v.mp4" poster="https://evil.example/p.png"></video><audio src="https://evil.example/a.mp3"></audio><picture><source srcset="https://evil.example/b.png"></picture>');

        $this->assertStringNotContainsString('evil.example', $result);
        foreach (['<img', '<video', '<audio', '<source', '<picture'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $result);
        }
        $this->assertStringContainsString('[Image – open in Plane to view]', $result);
    }

    public function test_drops_id_and_name_attributes(): void
    {
        $result = $this->sanitize('<p id="app">a</p><a id="Ziggy" name="Ziggy" href="https://plane.test">b</a><span name="querySelector">c</span>');

        $this->assertStringNotContainsString(' id=', $result);
        $this->assertStringNotContainsString(' name=', $result);
    }
}
