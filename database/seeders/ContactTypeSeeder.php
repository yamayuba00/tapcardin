<?php

namespace Database\Seeders;

use App\Models\ContactType;
use App\Models\ContactTypeGroup;
use App\Support\Contacts\ContactCatalog;
use App\Support\Contacts\ContactTypes;
use Illuminate\Database\Seeder;

/** Sinkronkan katalog jenis kontak (ContactCatalog) ke tabel; aman diulang, tidak menghapus jenis lama. */
class ContactTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ContactCatalog::groupRows() as $g) {
            ContactTypeGroup::updateOrCreate(['key' => $g['key']], $g);
        }
        $ids = ContactTypeGroup::pluck('id', 'key')->all();
        foreach (ContactCatalog::typeRows($ids) as $row) {
            ContactType::updateOrCreate(['key' => $row['key']], $row);
        }
        ContactTypes::forget();
    }
}
