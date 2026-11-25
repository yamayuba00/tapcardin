<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penanggung jawab per kartu di sebuah event: anggota yang membawa & memegang kartu itu selama event. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_cards', function (Blueprint $t) {
            $t->foreignId('assignee_user_id')->nullable()->after('card_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_cards', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assignee_user_id');
        });
    }
};
