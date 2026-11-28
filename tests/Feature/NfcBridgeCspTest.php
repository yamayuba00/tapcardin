<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Stasiun encode mode Reader USB tersambung ke konektor lokal ws://127.0.0.1 — hanya diizinkan di host panel. */
class NfcBridgeCspTest extends TestCase
{
    public function test_panel_csp_allows_local_nfc_bridge_websocket_and_keeps_nonce(): void
    {
        $csp = $this->get($this->host('panel', '/login'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("~connect-src 'self'[^;]* ws://127\.0\.0\.1:\*~", $csp);
        $this->assertMatchesRegularExpression("~script-src 'self' 'nonce-[A-Za-z0-9]{24}'~", $csp);
    }

    public function test_other_hosts_do_not_allow_local_nfc_bridge(): void
    {
        $csp = $this->get($this->host('app', '/login'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('127.0.0.1', $csp);
    }
}
