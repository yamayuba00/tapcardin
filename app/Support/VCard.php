<?php

namespace App\Support;

/**
 * TRD §10.1 — vCard 3.0: CRLF, line folding 75 oktet, escape `\` `,` `;` newline.
 */
final class VCard
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly string $fullName)
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [$fullName];
        $family = count($parts) > 1 ? array_pop($parts) : '';
        $given = implode(' ', $parts);
        $this->add('N', self::escape($family).';'.self::escape($given).';;;');
        $this->add('FN', self::escape($fullName));
    }

    public function org(?string $org): self
    {
        return $org ? $this->add('ORG', self::escape($org)) : $this;
    }

    public function title(?string $title): self
    {
        return $title ? $this->add('TITLE', self::escape($title)) : $this;
    }

    public function phone(string $e164, string $type = 'CELL'): self
    {
        return $this->add("TEL;TYPE=$type", self::escape($e164));
    }

    public function email(string $email): self
    {
        return $this->add('EMAIL;TYPE=INTERNET', self::escape($email));
    }

    public function url(string $url): self
    {
        return $this->add('URL', self::escape($url));
    }

    public function address(string $address): self
    {
        // ADR: PO box;extended;street;locality;region;postal;country — alamat bebas ke kolom street.
        return $this->add('ADR;TYPE=WORK', ';;'.self::escape($address).';;;;');
    }

    public function note(string $note): self
    {
        return $this->add('NOTE', self::escape($note));
    }

    public function photoJpeg(string $binary): self
    {
        return $this->add('PHOTO;ENCODING=b;TYPE=JPEG', base64_encode($binary));
    }

    public function render(): string
    {
        $out = ['BEGIN:VCARD', 'VERSION:3.0', ...$this->lines, 'END:VCARD'];

        return implode("\r\n", array_map(self::fold(...), $out))."\r\n";
    }

    public function filename(): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->fullName)), '-');

        return ($slug !== '' ? $slug : 'kontak').'.vcf';
    }

    private function add(string $name, string $value): self
    {
        $this->lines[] = "$name:$value";

        return $this;
    }

    public static function escape(string $v): string
    {
        return str_replace(['\\', ',', ';', "\r\n", "\n", "\r"], ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'], $v);
    }

    /** Lipat baris > 75 oktet tanpa memotong karakter UTF-8 multi-byte. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $chunks = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $chunks[] = $current;
                $current = '';
                $limit = 74; // baris lanjutan diawali satu spasi
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
