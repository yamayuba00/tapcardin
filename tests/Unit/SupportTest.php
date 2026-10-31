<?php

namespace Tests\Unit;

use App\Support\Base62;
use App\Support\Crockford;
use App\Support\Ndef;
use App\Support\Phone;
use App\Support\VCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_base62_token_has_length_and_alphabet(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $t = Base62::random(10);
            $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{10}$/', $t);
            $seen[$t] = true;
        }
        $this->assertCount(500, $seen, 'token harus unik secara praktis');
    }

    public function test_crockford_excludes_ambiguous_letters_and_normalizes_input(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[ILOU]/', Crockford::random(8));
        }
        $this->assertSame('7K2M9QXP', Crockford::normalize(' tc·7k2m-9qxp '));
        $this->assertSame('0110ABCD', Crockford::normalize('oiLo-abcd'));
        $this->assertTrue(Crockford::isValid('7K2M9QXP'));
        $this->assertFalse(Crockford::isValid('7K2M9QX'));
        $this->assertFalse(Crockford::isValid('7K2M9QXU'));
    }

    #[DataProvider('phones')]
    public function test_phone_normalizes_to_e164(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Phone::toE164($raw));
    }

    public static function phones(): array
    {
        return [
            ['0812-3456-7890', '+6281234567890'],
            ['+62 812 3456 7890', '+6281234567890'],
            ['6281234567890', '+6281234567890'],
            ['81234567890', '+6281234567890'],
            ['0065 9123 4567', '+6591234567'],
            ['+1 (415) 555-0100', '+14155550100'],
            ['123', null],
            ['', null],
            [null, null],
        ];
    }

    public function test_vcard_escapes_folds_and_uses_crlf(): void
    {
        $v = (new VCard('Rina Saputra'))
            ->org('PT Contoh; Divisi, Satu')
            ->title('Kepala Penjualan')
            ->phone('+6281234567890')
            ->email('rina@example.com')
            ->note(str_repeat('Profil panjang sekali ', 10));
        $out = $v->render();

        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\n", $out);
        $this->assertStringEndsWith("END:VCARD\r\n", $out);
        $this->assertStringContainsString("N:Saputra;Rina;;;\r\n", $out);
        $this->assertStringContainsString('ORG:PT Contoh\\; Divisi\\, Satu', $out);
        foreach (explode("\r\n", $out) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "baris melebihi 75 oktet: $line");
        }
        $this->assertStringContainsString("\r\n ", $out, 'NOTE panjang harus dilipat');
        $this->assertSame('rina-saputra.vcf', $v->filename());
    }

    public function test_vcard_fold_does_not_split_multibyte_characters(): void
    {
        $line = 'NOTE:'.str_repeat('é', 60);
        foreach (explode("\r\n", VCard::fold($line)) as $part) {
            $this->assertTrue(mb_check_encoding(ltrim($part, ' '), 'UTF-8'));
        }
    }

    public function test_ndef_uri_record_matches_trd_example(): void
    {
        // TRD §7.1: domain 14 karakter + token 10 → payload 28, record 32, TLV 35 byte
        $url = 'https://tap.domain.com/c/aB3dE5fG7h';
        $record = Ndef::uriRecord($url);
        $this->assertSame(32, strlen($record));
        $this->assertSame("\xD1\x01\x1CU\x04", substr($record, 0, 5));
        $this->assertSame('tap.domain.com/c/aB3dE5fG7h', substr($record, 5));
        $this->assertSame(35, strlen(Ndef::tlv($url)));
        $this->assertTrue(Ndef::fitsNtag213($url));
    }
}
