<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Backend Configuration & Safety Pass: config/cors.php replaced Laravel's
 * own packaged default (`allowed_origins: ['*']`, unconditional for
 * every environment, since this app previously had no config/cors.php at
 * all) with an environment-driven allowlist. These tests exercise the
 * real `HandleCors` middleware end-to-end via a real route (not just the
 * parsed config array), since that middleware -- not the array by
 * itself -- is what actually determines whether a browser accepts a
 * response.
 */
class CorsConfigurationTest extends TestCase
{
    public function test_the_env_parsing_splits_trims_and_drops_empty_entries(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://staging.example.com, https://admin.example.com ,,');

        $config = require base_path('config/cors.php');

        putenv('CORS_ALLOWED_ORIGINS');

        $this->assertSame(
            ['https://staging.example.com', 'https://admin.example.com'],
            $config['allowed_origins'],
        );
        $this->assertSame([], $config['allowed_origins_patterns']);
    }

    public function test_the_env_parsing_falls_back_to_a_loopback_pattern_when_unset(): void
    {
        putenv('CORS_ALLOWED_ORIGINS');

        $config = require base_path('config/cors.php');

        $this->assertSame([], $config['allowed_origins']);
        $this->assertNotEmpty($config['allowed_origins_patterns']);
    }

    public function test_paths_methods_and_headers_remain_fully_covered(): void
    {
        putenv('CORS_ALLOWED_ORIGINS');

        $config = require base_path('config/cors.php');

        $this->assertSame(['api/*', 'sanctum/csrf-cookie'], $config['paths']);
        $this->assertSame(['*'], $config['allowed_methods']);
        $this->assertSame(['*'], $config['allowed_headers']);
    }

    public function test_supports_credentials_stays_false(): void
    {
        putenv('CORS_ALLOWED_ORIGINS');

        $config = require base_path('config/cors.php');

        $this->assertFalse($config['supports_credentials']);
    }

    public function test_local_fallback_allows_a_loopback_origin_at_any_port(): void
    {
        // The real test-environment config: CORS_ALLOWED_ORIGINS is
        // unset (see phpunit.xml), so this exercises the genuine
        // local-development fallback, not a simulated one.
        $response = $this->get('/api/media/does-not-exist.png', [
            'Origin' => 'http://localhost:54321',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:54321');
    }

    public function test_local_fallback_does_not_allow_a_non_loopback_origin(): void
    {
        $response = $this->get('/api/media/does-not-exist.png', [
            'Origin' => 'https://evil.example.com',
        ]);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_configured_explicit_allowlist_allows_only_that_origin(): void
    {
        // Two configured origins (matching the real .env.example example
        // shape, "http://a,https://b") -- with only one origin
        // configured, fruitcake/php-cors treats it as always-safe-to-
        // advertise and echoes it unconditionally (browsers still refuse
        // to let the disallowed page read the response, since the
        // advertised origin won't match the page's own). With two or
        // more, it only echoes back the request's own Origin when that
        // origin is actually in the list -- the real case this test
        // means to exercise.
        Config::set('cors.allowed_origins', [
            'https://staging.example.com',
            'https://admin.example.com',
        ]);
        Config::set('cors.allowed_origins_patterns', []);

        $allowed = $this->get('/api/media/does-not-exist.png', [
            'Origin' => 'https://staging.example.com',
        ]);
        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://staging.example.com');

        // The loopback fallback no longer applies once a real allowlist
        // is configured -- mutually exclusive, not additive.
        $disallowed = $this->get('/api/media/does-not-exist.png', [
            'Origin' => 'http://localhost:54321',
        ]);
        $disallowed->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_single_configured_origin_is_always_advertised_but_never_the_loopback_fallback(): void
    {
        // With exactly one configured origin, the CORS library always
        // advertises that one value (a real, documented, spec-safe
        // optimization -- a browser still compares the advertised value
        // against the requesting page's own origin before allowing the
        // response to be read, so this never actually grants a
        // disallowed page access). What matters here is that it's never
        // the loopback fallback pattern, confirming the two are
        // genuinely mutually exclusive.
        Config::set('cors.allowed_origins', ['https://staging.example.com']);
        Config::set('cors.allowed_origins_patterns', []);

        $response = $this->get('/api/media/does-not-exist.png', [
            'Origin' => 'http://localhost:54321',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://staging.example.com');
    }
}
