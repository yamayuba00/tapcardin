<?php

namespace App\Support\Design;

/** Koleksi font desain cetak (OFL, public/fonts). Sumber tunggal dengan frontend: resources/js/lib/fonts.json. */
final class Fonts
{
    private static ?array $list = null;

    /** @return list<array{family:string,slug:string,category:string,weights:list<int>,width:float}> */
    public static function all(): array
    {
        return self::$list ??= json_decode((string) file_get_contents(resource_path('js/lib/fonts.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<int>> nama font → tebal yang tersedia */
    public static function weights(): array
    {
        return array_column(self::all(), 'weights', 'family');
    }

    public static function category(?string $family): ?string
    {
        return array_column(self::all(), 'category', 'family')[$family] ?? null;
    }

    /** Faktor lebar rata-rata karakter terhadap tinggi huruf (perkiraan lebar teks). */
    public static function width(?string $family): float
    {
        static $map = null;
        $map ??= array_column(self::all(), 'width', 'family');

        return (float) ($map[$family] ?? $map['IBM Plex Sans']);
    }
}
