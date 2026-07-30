<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Util\HtmlSanitizer;

final class ReleaseNotesRenderer
{
    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

    public function render(string $releaseNotes): string
    {
        $text = $this->plainText($releaseNotes);
        if ($text === '') {
            return '';
        }

        $html = $this->markdown($text);

        return $this->sanitizer->sanitize(
            $html,
            [
                'p' => [],
                'ul' => [],
                'ol' => [],
                'li' => [],
                'strong' => [],
                'em' => [],
                'code' => [],
                'pre' => [],
                'blockquote' => [],
                'h6' => [],
                'br' => [],
            ],
            true
        );
    }

    private function plainText(string $notes): string
    {
        $notes = \str_replace(["\r\n", "\r"], "\n", $notes);
        $notes = \preg_replace(
            '~<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>~isu',
            '',
            $notes
        ) ?? $notes;
        $notes = \preg_replace('~<br\s*/?>~iu', "\n", $notes) ?? $notes;
        $notes = \preg_replace(
            '~</(?:p|div|section|article|header|footer|h[1-6]|blockquote|pre|ul|ol|li)\s*>~iu',
            "\n",
            $notes
        ) ?? $notes;
        $notes = \preg_replace('~<li\b[^>]*>~iu', '- ', $notes) ?? $notes;
        $notes = \strip_tags($notes);
        $notes = \html_entity_decode($notes, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Keep link labels but never expose provider URLs. This covers inline
        // Markdown links, reference links, autolinks and raw URLs.
        $notes = \preg_replace('~!\[([^\]]*)\]\([^)\n]*\)~u', '$1', $notes) ?? $notes;
        $notes = \preg_replace('~\[([^\]]+)\]\([^)\n]*\)~u', '$1', $notes) ?? $notes;
        $notes = \preg_replace('~\[([^\]]+)\]\[[^\]]*\]~u', '$1', $notes) ?? $notes;
        $notes = \preg_replace('~^\s*\[[^\]]+\]:\s*\S+.*$~mu', '', $notes) ?? $notes;
        $notes = \preg_replace('~https?://[^\s<>()]+~iu', '', $notes) ?? $notes;
        $notes = \preg_replace('/[ \t]+\n/u', "\n", $notes) ?? $notes;
        $notes = \preg_replace('/\n{3,}/u', "\n\n", $notes) ?? $notes;

        return \trim($notes);
    }

    private function markdown(string $notes): string
    {
        $lines = \explode("\n", $notes);
        $html = '';
        $paragraph = [];
        $list = null;
        $code = [];
        $inCode = false;

        foreach ($lines as $line) {
            if (\preg_match('/^\s*```/', $line)) {
                $this->flushParagraph($html, $paragraph);
                $this->closeList($html, $list);
                if ($inCode) {
                    $html .= '<pre><code>'
                        . \htmlspecialchars(\implode("\n", $code), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                        . '</code></pre>';
                    $code = [];
                }
                $inCode = !$inCode;
                continue;
            }
            if ($inCode) {
                $code[] = $line;
                continue;
            }
            if (\trim($line) === '') {
                $this->flushParagraph($html, $paragraph);
                $this->closeList($html, $list);
                continue;
            }
            if (\preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $match)) {
                $this->flushParagraph($html, $paragraph);
                $this->closeList($html, $list);
                $html .= '<h6>' . $this->inline($match[1]) . '</h6>';
                continue;
            }
            if (\preg_match('/^\s*[-*+]\s+(.+)$/u', $line, $match)) {
                $this->flushParagraph($html, $paragraph);
                if ($list !== 'ul') {
                    $this->closeList($html, $list);
                    $html .= '<ul>';
                    $list = 'ul';
                }
                $html .= '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }
            if (\preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $match)) {
                $this->flushParagraph($html, $paragraph);
                if ($list !== 'ol') {
                    $this->closeList($html, $list);
                    $html .= '<ol>';
                    $list = 'ol';
                }
                $html .= '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }
            if (\preg_match('/^\s*>\s*(.+)$/u', $line, $match)) {
                $this->flushParagraph($html, $paragraph);
                $this->closeList($html, $list);
                $html .= '<blockquote>' . $this->inline($match[1]) . '</blockquote>';
                continue;
            }

            $this->closeList($html, $list);
            $paragraph[] = \trim($line);
        }

        if ($inCode && $code !== []) {
            $html .= '<pre><code>'
                . \htmlspecialchars(\implode("\n", $code), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '</code></pre>';
        }
        $this->flushParagraph($html, $paragraph);
        $this->closeList($html, $list);

        return $html;
    }

    /**
     * @param list<string> $paragraph
     */
    private function flushParagraph(string &$html, array &$paragraph): void
    {
        if ($paragraph === []) {
            return;
        }

        $html .= '<p>' . \implode('<br>', \array_map($this->inline(...), $paragraph)) . '</p>';
        $paragraph = [];
    }

    /**
     * @param-out null $list
     */
    private function closeList(string &$html, ?string &$list): void
    {
        if ($list === null) {
            return;
        }

        $html .= '</' . $list . '>';
        $list = null;
    }

    private function inline(string $text): string
    {
        $text = \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = \preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
        $text = \preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = \preg_replace('/__([^_]+)__/u', '<strong>$1</strong>', $text) ?? $text;
        $text = \preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;

        return $text;
    }
}
