<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RequestOtp;
use App\Actions\Auth\VerifyOtp;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** TRD §5.1 — email OTP tanpa password untuk pengguna workspace (app. & {slug}.). Staf memakai AdminLoginController. */
class OtpController extends Controller
{
    public function request(Request $request, RequestOtp $otp): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $otp->handle($data['email'], (string) $request->ip());

        return response()->json(['data' => ['sent' => true]]);
    }

    public function verify(Request $request, VerifyOtp $verify, LoginUser $login): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        // Tanpa transaksi: kenaikan `attempts` harus tersimpan walau verifikasi gagal (exception).
        $user = $login->byEmail($verify->handle($data['email'], $data['code']));

        return response()->json(['data' => ['user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email]]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }
}
