<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 028 — kontrak harus bergerak bersama rute (constitution: docs/openapi-pos-mvp.yaml diubah di
 * commit yang sama dengan perubahan rute). Dua jaga yang murah, tanpa dependensi YAML baru:
 *  1. setiap rute milik controller fitur restoran terdokumentasi dengan metode HTTP-nya;
 *  2. tidak ada `description:` tanpa kutip yang memuat kurung kurawal di dalam flow-mapping `{ … }`
 *     (itu merusak parsing YAML — pernah terjadi pada `ticket_item_id`).
 */
class OpenApiCoverageTest extends TestCase
{
    private const FEATURE_CONTROLLERS = [
        'DiningTableController', 'FloorController', 'TabController', 'TabLineController',
        'TabPaymentController', 'ModifierGroupController', 'StationController', 'KitchenController',
    ];

    /** @return array<string, array<int, string>> path ternormalisasi → daftar metode (huruf kecil) */
    private function documented(): array
    {
        $paths = [];
        $current = null;
        $inPaths = false;

        foreach (file(base_path('docs/openapi-pos-mvp.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $inPaths = true;
                continue;
            }
            if ($inPaths && preg_match('/^components:\s*$/', $line)) {
                break;
            }
            if (! $inPaths) {
                continue;
            }
            if (preg_match('/^  (\/\S*):\s*$/', $line, $m)) {
                $current = preg_replace('/\{[^}]+\}/', '{}', $m[1]);
                $paths[$current] ??= [];
            } elseif ($current && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $paths[$current][] = $m[1];
            }
        }

        return $paths;
    }

    public function test_every_restaurant_route_is_documented_with_its_method(): void
    {
        $documented = $this->documented();
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_starts_with($route->uri(), 'api/v1/') || ! collect(self::FEATURE_CONTROLLERS)->contains(fn ($c) => str_contains($action, $c))) {
                continue;
            }

            $path = '/'.preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), strlen('api/v1/')));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $method = strtolower($method);
                // apiResource memetakan PUT dan PATCH ke rute yang sama; dokumentasi cukup menyebut `put`.
                $accepted = $method === 'patch' && str_contains($action, '@update') ? ['patch', 'put'] : [$method];
                if (! array_intersect($accepted, $documented[$path] ?? [])) {
                    $missing[] = strtoupper($method)." $path ($action)";
                }
            }
        }

        $this->assertSame([], $missing, "Rute fitur restoran yang belum ada di docs/openapi-pos-mvp.yaml:\n".implode("\n", $missing));
    }

    public function test_the_scan_really_sees_documented_paths(): void
    {
        // Pengaman terhadap jaga yang diam-diam kosong (mis. format spec berubah).
        $documented = $this->documented();

        $this->assertGreaterThan(100, count($documented));
        $this->assertContains('post', $documented['/tabs/{}/payments'] ?? []);
        $this->assertContains('get', $documented['/floor'] ?? []);
    }

    public function test_no_unquoted_description_contains_braces_inside_a_flow_mapping(): void
    {
        $offenders = [];

        foreach (file(base_path('docs/openapi-pos-mvp.yaml'), FILE_IGNORE_NEW_LINES) as $i => $line) {
            // Dalam flow-mapping, scalar tanpa kutip berakhir di koma pertama (atau di `}` penutup);
            // hanya bagian itu yang diperiksa — kurung kurawal sesudah koma milik kunci berikutnya.
            if (preg_match('/: \{.*?description: ([^,"\'][^,]*?)(?:,| \}\s*$)/', $line, $m) && preg_match('/[{}]/', $m[1])) {
                $offenders[] = ($i + 1).': '.trim($line);
            }
        }

        $this->assertSame([], $offenders, "description tanpa kutip memuat { } di dalam flow-mapping:\n".implode("\n", $offenders));
    }
}
