<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt & sitemap.xml per host. Situs marketing, profil publik (tap.), halaman status, dan dokumentasi
 * webhook (wbh.) boleh dirayapi; aplikasi, panel, subdomain & domain kustom perusahaan tidak.
 */
class SeoController extends Controller
{
    public function robots(Request $request): Response
    {
        $base = config('tapcard.base_domain');
        $host = $request->getHost();
        $public = $host === $base || (bool) preg_match('/^(tap|status|wbh)\./', $host);
        $lines = ['User-agent: *'];
        if (! $public) {
            $lines[] = 'Disallow: /';
        } else {
            // /api/* tetap boleh: Googlebot memanggilnya saat merender halaman (etalase, status, dokumentasi webhook)
            $lines[] = 'Allow: /';
            if (str_starts_with($host, 'tap.')) {
                // QR sekali pakai & tautan berjangka: tidak berguna di hasil pencarian
                array_push($lines, 'Disallow: /q/', 'Disallow: /s/');
            }
            if ($host === $base) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$request->getSchemeAndHttpHost().'/sitemap.xml';
            }
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(Request $request): Response
    {
        abort_unless($request->getHost() === config('tapcard.base_domain'), 404);
        $url = e($request->getSchemeAndHttpHost().'/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            ."  <url><loc>$url</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>\n"
            .'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
