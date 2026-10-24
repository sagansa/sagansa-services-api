<?php

namespace Tests\Feature\Api;

use App\Models\Sop;
use App\Models\SopCategory;
use App\Models\SopStep;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint konten SOP (read-only): kategori aktif, daftar published
 * (filter kategori + cari judul + paginasi), dan detail beserta langkah.
 */
class SopEndpointTest extends TestCase
{
    private const CATEGORY_NAME = 'Kategori Test SOP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $category = SopCategory::query()->where('name', self::CATEGORY_NAME)->first();
        if ($category) {
            Sop::query()->where('sop_category_id', $category->id)->delete();
            $category->delete();
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makeCategory(bool $active = true): SopCategory
    {
        return SopCategory::create([
            'name' => self::CATEGORY_NAME,
            'is_active' => $active,
            'sort_order' => 1,
        ]);
    }

    private function makeSop(SopCategory $category, string $title, bool $published, int $stepCount = 0): Sop
    {
        $sop = Sop::create([
            'title' => $title,
            'sop_category_id' => $category->id,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
            'revision' => 1,
            'sort_order' => 1,
        ]);
        foreach (range(1, $stepCount) as $i) {
            SopStep::create([
                'sop_id' => $sop->id,
                'title' => "Langkah {$i}",
                'sort_order' => $i,
            ]);
        }
        return $sop->refresh();
    }

    public function test_kategori_hanya_menghitung_sop_terbit_dan_aktif(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $category = $this->makeCategory();
        $this->makeSop($category, 'Terbit A', true);
        $this->makeSop($category, 'Draft B', false);

        SopCategory::create(['name' => 'Kategori Mati Test SOP', 'is_active' => false]);
        try {
            $res = $this->getJson('/sop-categories');
            $res->assertOk();
            $rows = collect($res->json('data'));
            $row = $rows->firstWhere('name', self::CATEGORY_NAME);
            $this->assertNotNull($row, 'kategori aktif harus tampil');
            $this->assertSame(1, $row['sops_count'], 'draft tidak boleh terhitung');
            $this->assertNull($rows->firstWhere('name', 'Kategori Mati Test SOP'), 'kategori non-aktif tidak tampil');
        } finally {
            SopCategory::query()->where('name', 'Kategori Mati Test SOP')->delete();
        }
    }

    public function test_index_hanya_published_dengan_filter_dan_pencarian(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $category = $this->makeCategory();
        $other = SopCategory::create(['name' => 'Kategori Lain Test SOP', 'is_active' => true]);
        try {
            $this->makeSop($category, 'SOP Buka Kulkas Test', true);
            $this->makeSop($category, 'SOP Rahasia Test', false);
            $this->makeSop($other, 'SOP Buka Kulkas Lain Test', true);

            // Semua: 2 published.
            $res = $this->getJson('/sops');
            $res->assertOk();
            $this->assertCount(2, $res->json('data'));

            // Filter kategori.
            $res = $this->getJson('/sops?category_id=' . $category->id);
            $res->assertOk();
            $titles = array_column($res->json('data'), 'title');
            $this->assertEquals(['SOP Buka Kulkas Test'], $titles);

            // Pencarian judul.
            $res = $this->getJson('/sops?q=Kulkas');
            $res->assertOk();
            $this->assertCount(2, $res->json('data'));

            // Kombinasi filter + cari.
            $res = $this->getJson('/sops?category_id=' . $other->id . '&q=Kulkas');
            $res->assertOk();
            $this->assertCount(1, $res->json('data'));

            // Envelope meta ada untuk pola getRaw mobile.
            $this->assertArrayHasKey('meta', $res->json());
            $this->assertSame(1, $res->json('meta.current_page'));
        } finally {
            Sop::query()->whereIn('sop_category_id', [$category->id, $other->id])->delete();
            $other->delete();
        }
    }

    public function test_detail_menyertakan_langkah_terurut_dan_menolak_draft(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $category = $this->makeCategory();
        $published = $this->makeSop($category, 'SOP Berlangkah Test', true, stepCount: 3);
        $draft = $this->makeSop($category, 'SOP Draft Detail Test', false, stepCount: 1);

        $res = $this->getJson('/sops/' . $published->id);
        $res->assertOk();
        $steps = $res->json('data.steps');
        $this->assertCount(3, $steps);
        $this->assertSame(['Langkah 1', 'Langkah 2', 'Langkah 3'], array_column($steps, 'title'));

        $this->getJson('/sops/' . $draft->id)->assertNotFound();
        $this->getJson('/sops/999999999')->assertNotFound();
    }
}
