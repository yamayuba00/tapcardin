<?php

use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Channel privat per pengguna: notifikasi in-app (tap, koneksi baru, pembayaran, pesanan, impor, …).
Broadcast::channel('user.{publicId}', fn (User $user, string $publicId) => $user->public_id === $publicId, ['guards' => ['web']]);

// Dasbor uptime di panel: setiap hasil cek & perubahan status, hanya untuk staf platform aktif.
Broadcast::channel('platform.uptime', fn (StaffAccount $staff) => $staff->isActive(), ['guards' => ['staff']]);
