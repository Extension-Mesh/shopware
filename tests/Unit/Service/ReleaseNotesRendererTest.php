<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Service\ReleaseNotesRenderer;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Util\HtmlSanitizer;

final class ReleaseNotesRendererTest extends TestCase
{
    public function testItRendersUsefulStructureWithoutHtmlOrRepositoryUrls(): void
    {
        $renderer = new ReleaseNotesRenderer(new HtmlSanitizer(cacheEnabled: false));
        $html = $renderer->render(<<<'NOTES'
            <h2>Security fixes</h2>
            <script>alert('no')</script>

            ### Changes

            - **Fixed** [private issue](https://github.com/acme/private/issues/12)
            - Kept `inline code`

            <p>See <a href="https://github.com/acme/private">the repository</a>.</p>
            Raw URL: https://github.com/acme/private/commit/secret
            NOTES);

        self::assertStringContainsString('Security fixes', $html);
        self::assertStringContainsString('<h6>Changes</h6>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<strong>Fixed</strong> private issue', $html);
        self::assertStringContainsString('<code>inline code</code>', $html);
        self::assertStringContainsString('the repository', $html);
        self::assertStringNotContainsString('github.com', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert', $html);
    }
}
