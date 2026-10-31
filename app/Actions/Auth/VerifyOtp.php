<?php

namespace App\Actions\Auth;

use App\Models\LoginOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** TRD §5.1 — maksimal 5 percobaan per OTP. Mengembalikan email yang terbukti dimiliki. */
final class VerifyOtp
{
    public function handle(string $email, string $code, string $purpose = 'login'): string
    {
        $email = strtolower(trim($email));
        $otp = LoginOtp::where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->where('expires_at', '>', now())
            ->latest('id')->first();

        if (! $otp) {
            throw ValidationException::withMessages(['code' => 'Kode sudah tidak berlaku. Minta kode baru.']);
        }

        if (! Hash::check(preg_replace('/\D/', '', $code), $otp->code_hash)) {
            // increment atomik di DB agar percobaan paralel tetap terhitung
            LoginOtp::whereKey($otp->id)->increment('attempts');
            $left = 5 - $otp->fresh()->attempts;
            if ($left <= 0) {
                $otp->update(['consumed_at' => now()]);
                throw ValidationException::withMessages(['code' => 'Kode salah 5 kali. Minta kode baru.']);
            }
            throw ValidationException::withMessages(['code' => "Kode belum cocok. Sisa $left percobaan."]);
        }

        $otp->update(['consumed_at' => now()]);

        return $email;
    }
}
