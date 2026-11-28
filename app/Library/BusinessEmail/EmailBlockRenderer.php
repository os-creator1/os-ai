<?php

namespace App\Library\BusinessEmail;

use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Library\Documents\Blocks\BlockSchema;

/**
 * First shared-editor email building block: use the existing contract editor's
 * structured content vocabulary, but render it as email-safe inline HTML.
 *
 * This is a pure renderer, not a send operation. The caller must supply the
 * canonical, tenant-scoped merge resolver. Image assets, promotional consent
 * and sending are deliberately not wired by this foundation.
 */
final class EmailBlockRenderer
{
    public const TYPES = ['heading', 'text', 'divider', 'spacer', 'section'];

    /** @return list<array{id: string, type: string, data: array<string, mixed>}> */
    public function normalize(mixed $blocks): array
    {
        $normalized = BlockSchema::normalize($blocks, ['allow_images' => false, 'strict_keys' => true]);

        foreach ($normalized as $index => $block) {
            if (! in_array($block['type'], self::TYPES, true)) {
                throw new InvalidDocumentBlocksException(["blocks.{$index}.type" => 'This block is not available in emails.']);
            }

            foreach ($block['data']['runs'] ?? [] as $runIndex => $run) {
                if (str_starts_with($run['merge'] ?? '', 'document.')) {
                    throw new InvalidDocumentBlocksException(["blocks.{$index}.data.runs.{$runIndex}.merge" => 'Document fields are not available in emails.']);
                }
            }
        }

        return $normalized;
    }

    /**
     * @param callable(string): string $resolveMerge
     * @return array{html: string, text: string}
     */
    public function render(mixed $blocks, callable $resolveMerge): array
    {
        $rows = [];
        $plain = [];

        foreach ($this->normalize($blocks) as $block) {
            $data = $block['data'];

            if ($block['type'] === 'divider') {
                $rows[] = '<tr><td style="padding:12px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-top:1px solid #dddddd;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>';
                continue;
            }

            if ($block['type'] === 'spacer') {
                $height = $data['height'];
                $rows[] = '<tr><td height="' . $height . '" style="height:' . $height . 'px;font-size:0;line-height:0;">&nbsp;</td></tr>';
                continue;
            }

            if ($block['type'] === 'section') {
                $rows[] = '<tr><td style="padding:12px 24px;font-size:20px;font-weight:bold;">' . self::escape($data['title']) . '</td></tr>';
                $plain[] = $data['title'];
                continue;
            }

            $html = '';
            $text = '';

            foreach ($data['runs'] as $run) {
                $value = isset($run['merge']) ? $resolveMerge($run['merge']) : $run['t'];
                $inner = nl2br(self::escape($value), false);
                foreach (['b', 'i', 'u'] as $tag) {
                    if (($run[$tag] ?? false) === true) {
                        $inner = "<{$tag}>{$inner}</{$tag}>";
                    }
                }

                $text .= $value;
                if (isset($run['href'])) {
                    $href = $run['href'];
                    $inner = '<a href="' . self::escape($href) . '" style="color:#2459b5;text-decoration:underline;">' . $inner . '</a>';
                    if ($value !== $href) {
                        $text .= ' (' . $href . ')';
                    }
                }

                $html .= $inner;
            }

            $style = 'padding:8px 24px;text-align:' . $data['align'] . ';';
            if ($block['type'] === 'heading') {
                $level = $data['level'];
                $size = [1 => 28, 2 => 24, 3 => 20][$level];
                $html = '<h' . $level . ' style="margin:0;font-size:' . $size . 'px;line-height:1.3;">' . $html . '</h' . $level . '>';
            }
            $rows[] = '<tr><td style="' . $style . '">' . $html . '</td></tr>';
            $plain[] = $text;
        }

        return [
            'html' => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f2f4f6;"><tr><td align="center" style="padding:16px 0;">'
                . '<!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#ffffff;font-family:Arial,sans-serif;font-size:16px;line-height:1.5;color:#222222;">'
                . implode('', $rows) . '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table>',
            'text' => implode("\n\n", $plain),
        ];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
