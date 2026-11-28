<?php

namespace App\Support\Blast;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;

/**
 * Markdown email blast → HTML aman untuk email (gaya inline). HTML mentah dari editor TIDAK pernah lolos
 * (html_input=escape), tautan berbahaya dibuang (allow_unsafe_links=false), dan tautan hanya boleh URL absolut
 * https (gambar juga boleh dari disk `blast` sendiri). Tombol: baris `[[Tombol: Teks | https://url]]`.
 */
final class BlastMarkdown
{
    public const BUTTON = '/^[ \t]*\[\[[ \t]*(?:tombol|button)[ \t]*:[ \t]*(.+?)[ \t]*\|[ \t]*(\S+?)[ \t]*\]\][ \t]*$/imu';

    private const FONT = "font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    private const STYLES = [
        'p' => 'margin:0 0 16px;font-size:16px;line-height:1.6;color:#1f2328;',
        'h1' => 'margin:0 0 16px;font-size:26px;line-height:1.25;font-weight:700;color:#0d1117;',
        'h2' => 'margin:24px 0 12px;font-size:21px;line-height:1.3;font-weight:700;color:#0d1117;',
        'h3' => 'margin:20px 0 10px;font-size:17px;line-height:1.35;font-weight:700;color:#0d1117;',
        'list' => 'margin:0 0 16px;padding-left:24px;font-size:16px;line-height:1.6;color:#1f2328;',
        'li' => 'margin:0 0 6px;',
        'a' => 'color:#0b57d0;text-decoration:underline;',
        'img' => 'display:block;width:100%;max-width:100%;height:auto;border:0;margin:0 0 16px;',
        'hr' => 'border:0;border-top:1px solid #d8dee4;margin:24px 0;',
        'quote' => 'margin:0 0 16px;padding:4px 0 4px 16px;border-left:3px solid #d8dee4;color:#59636e;',
        'code' => 'font-family:Consolas,Menlo,monospace;font-size:14px;background:#f3f4f6;padding:1px 4px;border-radius:4px;',
        'pre' => 'font-family:Consolas,Menlo,monospace;font-size:14px;background:#f3f4f6;padding:12px;border-radius:6px;white-space:pre-wrap;margin:0 0 16px;',
    ];

    /** URL absolut https; untuk gambar juga URL berawalan alamat disk blast sendiri (mis. http://localhost/storage/blast di lokal). */
    public static function safeUrl(string $url, bool $image = false): ?string
    {
        $url = trim($url);
        if (preg_match('~^https://[^\s/?#@]+\.[^\s/?#@]+([/?#]\S*)?$~i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        if ($image) {
            $own = rtrim((string) Storage::disk('blast')->url('x'), 'x');
            if (preg_match('~^https?://~', $own) && str_starts_with($url, $own) && ! preg_match('~\s|\.\.~', $url)) {
                return $url;
            }
        }

        return null;
    }

    public static function html(string $markdown): string
    {
        $nonce = 'BLASTBTN'.Str::random(12);
        $buttons = [];
        $md = preg_replace_callback(self::BUTTON, function ($m) use (&$buttons, $nonce) {
            $buttons[] = [trim($m[1]), $m[2]];

            return "\n\n{$nonce}x".(count($buttons) - 1)."x\n\n";
        }, str_replace("\r\n", "\n", $markdown));

        $env = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $e) => self::decorate($e->getDocument()));
        $html = (string) (new MarkdownConverter($env))->convert($md);

        return preg_replace_callback('~<p[^>]*>'.$nonce.'x(\d+)x</p>~', function ($m) use ($buttons) {
            [$label, $url] = $buttons[(int) $m[1]];
            $safe = self::safeUrl($url);

            return $safe ? self::button($label, $safe) : '<p style="'.self::FONT.self::STYLES['p'].'">'.e($label).'</p>';
        }, $html);
    }

    /** Tombol "anti-peluru" (tabel + tautan), tampil sama di Outlook/Gmail. */
    public static function button(string $label, string $url): string
    {
        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr>'
            .'<td style="border-radius:8px;background:#0d1117;">'
            .'<a href="'.e($url).'" target="_blank" rel="noopener" style="display:inline-block;padding:12px 24px;'.self::FONT
            .'font-size:16px;font-weight:600;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:8px;">'.e($label).'</a>'
            .'</td></tr></table>';
    }

    /** Versi teks polos (alternatif multipart) dari markdown yang sama. */
    public static function text(string $markdown): string
    {
        $t = str_replace("\r\n", "\n", $markdown);
        $t = preg_replace_callback(self::BUTTON, fn ($m) => trim($m[1]).(($u = self::safeUrl($m[2])) ? ': '.$u : ''), $t);
        $t = preg_replace('/!\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)(?:\s+"[^"]*")?\)/u', '$1', $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\(((?:[^()\s]|\([^()\s]*\))+)(?:\s+"[^"]*")?\)/u', fn ($m) => $m[1].(($u = self::safeUrl($m[2])) ? " ($u)" : ''), $t);
        $t = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+/mu', '', $t);
        $t = preg_replace('/^[ \t]{0,3}([-*_])([ \t]*\1){2,}[ \t]*$/mu', '----------', $t);
        $t = str_replace(['**', '__'], '', $t);

        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    private static function decorate(Node $doc): void
    {
        foreach (iterator_to_array($doc->iterator(), false) as $node) {
            if ($node instanceof Link) {
                $url = self::safeUrl($node->getUrl());
                if (! $url) {
                    self::unwrap($node);

                    continue;
                }
                $node->setUrl($url);
                $node->data->set('attributes', ['style' => self::STYLES['a'], 'target' => '_blank', 'rel' => 'noopener']);

                continue;
            }
            if ($node instanceof Image) {
                $url = self::safeUrl($node->getUrl(), true);
                if (! $url) {
                    $parent = $node->parent();
                    $node->detach();
                    if ($parent instanceof Paragraph && ! $parent->hasChildren()) {
                        $parent->detach();
                    }

                    continue;
                }
                $node->setUrl($url);
                $node->data->set('attributes', ['style' => self::STYLES['img'], 'width' => '100%']);

                continue;
            }
            $style = match (true) {
                $node instanceof Paragraph => self::FONT.self::STYLES['p'],
                $node instanceof Heading => self::FONT.self::STYLES['h'.min(3, $node->getLevel())],
                $node instanceof ListBlock => self::FONT.self::STYLES['list'],
                $node instanceof ListItem => self::STYLES['li'],
                $node instanceof ThematicBreak => self::STYLES['hr'],
                $node instanceof BlockQuote => self::STYLES['quote'],
                $node instanceof Code => self::STYLES['code'],
                $node instanceof FencedCode, $node instanceof IndentedCode => self::STYLES['pre'],
                default => null,
            };
            if ($style) {
                $node->data->set('attributes', ['style' => $style]);
            }
        }
    }

    /** Tautan tidak aman → teksnya tetap, tautannya hilang. */
    private static function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }
        $node->detach();
    }
}
