<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 048 / T004 / SC-010 — pagar kontrak: setiap rute `/api/v1` HARUS ada di `docs/openapi-pos-mvp.yaml`, kecuali yang
 * tercatat di `tests/Support/openapi-known-gaps.php`. Daftar pengecualian itu HANYA boleh menyusut: rute yang sudah
 * terdokumentasi tetapi masih tercatat sebagai celah membuat uji ini gagal, sehingga celah yang ditutup harus dihapus
 * dari daftar. Menambah rute tanpa dokumentasi juga gagal (kebijakan konstitusi: kontrak berubah di commit yang sama).
 */
class OpenApiCoverageTest extends TestCase
{
    /** @return array<string, true> kunci "METHOD /path" dengan {param} dinormalkan menjadi {} */
    private function documented(): array
    {
        $found = [];
        $path = null;
        foreach (file(base_path('docs/openapi-pos-mvp.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^  (\/[^\s:]*):\s*$/', $line, $m)) {
                $path = $this->normalise($m[1]);
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $found[strtoupper($m[1]).' '.$path] = true;
            } elseif (preg_match('/^\S/', $line) && ! str_starts_with($line, 'paths:')) {
                $path = null;
            }
        }

        return $found;
    }

    private function normalise(string $uri): string
    {
        return '/'.trim(preg_replace('/\{[^}]+\??\}/', '{}', preg_replace('#^api/v1#', '', '/'.ltrim($uri, '/'))), '/');
    }

    /** @return list<string> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $out[] = $method.' '.$this->normalise(substr($route->uri(), strlen('api/v1')));
            }
        }

        return array_values(array_unique($out));
    }

    public function test_every_route_is_documented_or_a_known_gap(): void
    {
        $documented = $this->documented();
        $gaps = array_flip(require base_path('tests/Support/openapi-known-gaps.php'));

        $undocumented = array_values(array_filter($this->routes(), fn ($r) => ! isset($documented[$r])));
        $unexpected = array_values(array_filter($undocumented, fn ($r) => ! isset($gaps[$r])));
        $this->assertSame([], $unexpected, "Rute baru belum ada di docs/openapi-pos-mvp.yaml:\n".implode("\n", $unexpected));
    }

    public function test_the_gap_list_only_shrinks(): void
    {
        $documented = $this->documented();
        $routes = array_flip($this->routes());
        $stale = [];
        foreach (require base_path('tests/Support/openapi-known-gaps.php') as $gap) {
            if (isset($documented[$gap]) || ! isset($routes[$gap])) {
                $stale[] = $gap;
            }
        }
        $this->assertSame([], $stale, "Hapus dari tests/Support/openapi-known-gaps.php (sudah terdokumentasi atau rute sudah tidak ada):\n".implode("\n", $stale));
    }
}
