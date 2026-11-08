<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BaseConnection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Penjaga zona waktu: Laravel memformat tanggal ke query & kolom model sebagai `Y-m-d H:i:s` TANPA offset, sehingga
 * Carbon berzona WIB (mis. `now('Asia/Jakarta')->startOfDay()`) terbaca Postgres sebagai UTC → bergeser 7 jam.
 * Di sini format menyertakan offset (`+07:00`), jadi kolom timestamptz selalu menerima waktu yang benar, apa pun zona
 * Carbon-nya. Kolom `date` tetap mengambil bagian tanggal saja; nilai dari database tetap terbaca seperti biasa.
 */
class PostgresConnection extends BaseConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new class($this) extends PostgresGrammar
        {
            public function getDateFormat()
            {
                return 'Y-m-d H:i:sP';
            }
        };
    }
}
