<?php

declare(strict_types=1);

namespace App\Service\Plane;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Turns the description HTML of a Plane work item into HTML that is safe to render in solidtime.
 */
class PlaneDescriptionSanitizer
{
    private const string IMAGE_PLACEHOLDER = '<p><em>[Image – open in Plane to view]</em></p>';

    private const string MENTION_PLACEHOLDER = '@mention';

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Plane's editor stores images and mentions as custom elements that only reference Plane ids.
        // Plane's asset API is not usable with an API key, so images are replaced by a placeholder.
        // Security does not depend on these replacements: the sanitizer below removes everything unsafe.
        $html = (string) preg_replace('/<image-component\b[^>]*>(\s*<\/image-component>)?/i', self::IMAGE_PLACEHOLDER, $html);
        $html = (string) preg_replace('/<mention-component\b[^>]*>(\s*<\/mention-component>)?/i', self::MENTION_PLACEHOLDER, $html);
        // Plain images would load from any host and let a Plane author track who opens the ticket.
        $html = (string) preg_replace('/<img\b[^>]*>/i', self::IMAGE_PLACEHOLDER, $html);

        $sanitized = $this->sanitizer()->sanitize($html);

        // Plane stores an empty description as "<p></p>".
        return trim(html_entity_decode(strip_tags($sanitized))) === '' ? '' : $sanitized;
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropAttribute('class', '*')
            ->dropAttribute('data-id', '*')
            // id/name could clobber DOM globals (f.e. document.querySelector) or collide with ids of the page
            ->dropAttribute('id', '*')
            ->dropAttribute('name', '*')
            // No remote media: it would be loaded from any host the Plane author chooses
            ->dropElement('img')
            ->dropElement('picture')
            ->dropElement('source')
            ->dropElement('video')
            ->dropElement('audio')
            ->dropElement('track')
            ->withMaxInputLength(200_000);

        return new HtmlSanitizer($config);
    }
}
