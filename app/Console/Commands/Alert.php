<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\Role;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Alarm email ke pemilik platform dari hasil `tapcard:health` (tiap 5 menit).
 *  - masalah baru → email segera; masalah sementara (antrean sesaat lambat, dsb.) harus muncul 2x berturut-turut dulu,
 *    kecuali database mati yang langsung dikabari;
 *  - masih berlangsung → pengingat tiap `alert.remind_hours` jam;
 *  - semua pulih → satu email "kembali normal".
 * Pemeriksaan bertanda `advisory` (saran kapasitas) tidak memicu alarm.
 */
class Alert extends Command
{
    protected $signature = 'tapcard:alert';

    protected $description = 'Kirim email ke pemilik platform bila pemeriksaan kesehatan gagal';

    private const IMMEDIATE = ['Koneksi database'];

    public function handle(): int
    {
        $h = Health::collect();
        $failing = collect($h['checks'])->reject(fn ($c) => $c['ok'] || ($c['advisory'] ?? false))->keyBy('label');

        $state = Cache::get('alert:state', ['streak' => [], 'alerted' => [], 'at' => null]);
        $streak = $failing->keys()->mapWithKeys(fn ($l) => [$l => ($state['streak'][$l] ?? 0) + 1])->all();
        $confirmed = $failing->filter(fn ($c, $l) => $streak[$l] >= 2 || in_array($l, self::IMMEDIATE, true));

        $new = $confirmed->keys()->diff($state['alerted']);
        $due = $state['at'] && now()->timestamp - $state['at'] >= config('tapcard.alert.remind_hours') * 3600;
        $alerted = array_values(array_intersect($state['alerted'], $failing->keys()->all())); // yang pulih dilepas
        $at = $state['at'];

        if ($new->isNotEmpty() || ($confirmed->isNotEmpty() && $due)) {
            $sent = $this->send('system_alert', ['checks' => $confirmed->values()->all(), 'reminder' => $new->isEmpty()]);
            if ($sent) {
                $alerted = $confirmed->keys()->all();
                $at = now()->timestamp;
            }
            $this->line(($sent ? 'Alarm terkirim: ' : 'Alarm GAGAL dikirim (tidak ada penerima): ').$confirmed->keys()->join(', '));
        } elseif ($failing->isEmpty() && $state['alerted']) {
            $this->send('system_recovered', ['labels' => $state['alerted']]);
            [$alerted, $at] = [[], null];
            $this->line('Pulih: '.implode(', ', $state['alerted']));
        } else {
            $this->line($failing->isEmpty() ? 'Semua normal.' : 'Dipantau: '.$failing->keys()->join(', '));
        }

        Cache::forever('alert:state', ['streak' => $streak, 'alerted' => $alerted, 'at' => $at]);

        return self::SUCCESS;
    }

    private function send(string $type, array $data): bool
    {
        $to = $this->recipients();
        $data['url'] = $this->panelUrl().'/system';
        foreach ($to as $email => $name) {
            Notify::email($email, $name, $type, $data, now: true);
        }

        return $to !== [];
    }

    /** @return array<string,string> email => nama. ALERT_EMAILS dulu (tetap jalan walau database mati), lalu Pemilik Platform. */
    private function recipients(): array
    {
        $list = array_fill_keys(config('tapcard.alert.emails'), 'Tim TapCard');
        try {
            PlatformStaff::with('user')->where('status', 'active')->where('role_id', Role::system('platform_owner')->id)->get()
                ->each(function ($s) use (&$list) {
                    if ($s->user && ! $s->user->trashed()) {
                        $list[$s->user->email] = $s->user->name;
                    }
                });
        } catch (Throwable) {
            // database mati → hanya alamat dari ALERT_EMAILS
        }

        return $list;
    }

    private function panelUrl(): string
    {
        $u = parse_url((string) config('app.url'));

        return ($u['scheme'] ?? 'https').'://'.config('tapcard.panel_subdomain').'.'.config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '');
    }
}
