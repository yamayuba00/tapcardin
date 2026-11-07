<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Channel privat per pengguna: notifikasi in-app (tap, koneksi baru, pembayaran, pesanan, impor, …).
Broadcast::channel('user.{publicId}', fn (User $user, string $publicId) => $user->public_id === $publicId);
