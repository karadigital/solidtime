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
        $this->assertSame('<p></p>', $this->sanitize('<p></p>'));
    }
}
