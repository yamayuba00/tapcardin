<?php

namespace Tests\Feature;

use App\Http\Controllers\Marketing\ShowcaseController;
use App\Support\Marketing\Landing;
use App\Support\Marketing\LandingSsr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** SEO situs marketing: meta & JSON-LD, render server (SSR), robots.txt & sitemap.xml per host, noindex aplikasi. */
class LandingSeoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function base(string $path = '/'): string
    {
        return 'http://localhost'.$path;
    }

    public function test_home_has_meta_open_graph_and_json_ld_from_one_faq_source(): void
    {
        $html = $this->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.e(Landing::TITLE).'</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="'.e(Landing::DESCRIPTION).'">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="http://localhost/og-tapcard.png">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertFileExists(public_path('og-tapcard.png'));

        preg_match('#<script type="application/ld\+json"[^>]*>(.+?)</script>#s', $html, $m);
        $ld = json_decode($m[1] ?? '{}', true);
        $types = array_column($ld['@graph'] ?? [], '@type');
        foreach (['Organization', 'WebSite', 'Product', 'SoftwareApplication', 'FAQPage'] as $type) {
            $this->assertContains($type, $types);
        }
        $faqPage = collect($ld['@graph'])->firstWhere('@type', 'FAQPage');
        $api = $this->getJson($this->base('/api/showcase'))->assertOk()->json('data.faq');
        $this->assertSame(array_column($api, 'q'), array_column($faqPage['mainEntity'], 'name'), 'FAQ halaman = FAQ JSON-LD');
        $product = collect($ld['@graph'])->firstWhere('@type', 'Product');
        $this->assertSame('IDR', $product['offers']['priceCurrency']);
        $this->assertGreaterThan(0, (int) $product['offers']['lowPrice']);
    }

    public function test_home_is_rendered_on_the_server_when_the_ssr_bundle_exists(): void
    {
        if (! is_file(LandingSsr::bundle())) {
            $this->markTestSkipped('Bundle SSR belum dibangun (npm run build).');
        }
        Cache::flush();
        $html = $this->get($this->base())->assertOk()->getContent();
        preg_match('#<div id="app"[^>]*>(.*)</div>\s*</body>#s', $html, $m);
        $this->assertStringContainsString('<h1', $m[1] ?? '', 'isi #app hasil SSR');
        $this->assertStringContainsString(e(Landing::faq(ShowcaseController::data())[1]['q']), $m[1]);
    }

    public function test_ssr_failure_falls_back_to_the_plain_spa(): void
    {
        config(['tapcard.ssr.node' => 'node-yang-tidak-ada-'.uniqid()]);
        Cache::flush();
        $html = $this->get($this->base())->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div id="app" data-config=\'[^\']*\'></div>#', $html);
    }

    public function test_robots_and_sitemap_follow_the_host(): void
    {
        $this->get($this->base('/robots.txt'))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Allow: /', false)->assertSee('Sitemap: http://localhost/sitemap.xml', false);
        $this->get($this->host('tap', '/robots.txt'))->assertOk()->assertSee('Disallow: /q/', false)->assertDontSee('Sitemap:', false);
        foreach (['app', 'panel', 'majujaya'] as $h) {
            $this->get($this->host($h, '/robots.txt'))->assertOk()->assertSee("Disallow: /\n", false);
        }
        $this->get($this->host('wbh', '/robots.txt'))->assertOk()->assertSee('Allow: /', false);

        $this->get($this->base('/sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertSee('<loc>http://localhost/</loc>', false);
        $this->get($this->host('tap', '/sitemap.xml'))->assertNotFound();
    }

    public function test_sales_whatsapp_number_reaches_the_landing_only_when_configured(): void
    {
        $config = fn (string $html) => json_decode(html_entity_decode(preg_match("#data-config='([^']*)'#", $html, $m) ? $m[1] : '{}'), true);

        config(['tapcard.sales_whatsapp' => '']);
        $this->assertNull($config($this->get($this->base())->getContent())['salesWhatsapp']);

        config(['tapcard.sales_whatsapp' => '6281234567890']);
        $this->assertSame('6281234567890', $config($this->get($this->base())->getContent())['salesWhatsapp']);
        $this->assertNull($config($this->get($this->host('app', '/login'))->getContent())['salesWhatsapp'], 'hanya situs marketing');
    }

    public function test_apps_are_noindex_while_marketing_and_public_profiles_are_not(): void
    {
        $this->get($this->host('app', '/login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($this->host('panel', '/login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($this->base())->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->get($this->base('/halaman-tidak-ada'))->assertHeader('X-Robots-Tag', 'noindex');
    }
}
