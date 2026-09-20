<?php

namespace Tests\Feature\Api;

use App\Models\RemainingStorage;
use App\Models\DetailStockCard;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorageStockUpdateTest extends TestCase
{
    /** Report ids yang dibuat test ini — dibersihkan di tearDown. */
    private array $createdReportIds = [];

    public function test_updated_by_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_cards', 'updated_by'));
    }

    private function userWithRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    /**
     * Laporan remaining_storage baru untuk store pertama di dev DB.
     * Default date jauh di masa depan agar selalu "terakhir" untuk
     * store-nya (guard Task 3 tidak mengganggu test Task 2).
     */
    private function makeReport(array $attributes = []): RemainingStorage
    {
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        $report = RemainingStorage::create(array_merge([
            'for' => 'remaining_storage',
            'store_id' => $store->id,
            'date' => now()->addYears(5)->toDateString(),
            'user_id' => null,
            'status' => 1,
        ], $attributes));

        DetailStockCard::create([
            'stock_card_id' => $report->id,
            'product_id' => $this->firstProductId(),
            'quantity' => 10,
        ]);

        $this->createdReportIds[] = $report->id;
        return $report;
    }

    private function firstProductId(): int
    {
        $product = \App\Models\Product::first();
        if (!$product) {
            $this->markTestSkipped('Need at least 1 product in database');
        }
        return $product->id;
    }

    private function payloadItem(float $quantity): array
    {
        return ['product_id' => $this->firstProductId(), 'quantity' => $quantity];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdReportIds as $id) {
            DetailStockCard::where('stock_card_id', $id)->delete();
            RemainingStorage::where('id', $id)->delete();
        }
        $this->createdReportIds = [];
        parent::tearDown();
    }

    public function test_storage_staff_can_edit_report_of_any_store(): void
    {
        $editor = $this->userWithRole('storage-staff');
        $owner = \App\Models\User::factory()->create();
        $report = $this->makeReport(['user_id' => $owner->id]);

        Sanctum::actingAs($editor);
        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(25)],
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $fresh = $report->fresh();
        $this->assertEquals($editor->id, $fresh->updated_by);
        $this->assertEquals($owner->id, $fresh->user_id); // pelapor awal tidak berubah
        $this->assertEquals($report->store_id, $fresh->store_id);
        $this->assertEquals($report->date, $fresh->date);

        $details = $fresh->detailStockCards()->get();
        $this->assertCount(1, $details);
        $this->assertEquals($this->firstProductId(), $details->first()->product_id);
        $this->assertEquals(25, (float) $details->first()->quantity);
    }

    public function test_admin_can_edit_report(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $report = $this->makeReport();

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(7)],
        ]);

        $res->assertOk();
    }

    public function test_staff_cannot_edit_report(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $report = $this->makeReport();

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(5)],
        ]);

        $res->assertStatus(403);
        $this->assertNull($report->fresh()->updated_by);
    }

    public function test_sales_role_cannot_edit_report(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));
        $report = $this->makeReport();

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(5)],
        ]);

        $res->assertStatus(403);
    }

    public function test_update_returns_404_for_missing_report(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));

        $res = $this->putJson('/storage-stocks/99999999', [
            'items' => [$this->payloadItem(1)],
        ]);

        $res->assertStatus(404);
    }

    public function test_invalid_payload_returns_422(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $report = $this->makeReport();

        $res = $this->putJson("/storage-stocks/{$report->id}", ['items' => []]);
        $res->assertStatus(422);

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [['product_id' => 99999999, 'quantity' => 1]],
        ]);
        $res->assertStatus(422);

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [['product_id' => $this->firstProductId(), 'quantity' => -5]],
        ]);
        $res->assertStatus(422);
    }

    public function test_store_id_and_date_are_ignored(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $report = $this->makeReport();

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'store_id' => $report->store_id,
            'date' => '2030-01-01',
            'items' => [$this->payloadItem(3)],
        ]);

        $res->assertOk();
        $fresh = $report->fresh();
        $this->assertEquals($report->store_id, $fresh->store_id);
        $this->assertEquals($report->date, $fresh->date);
    }

    public function test_cannot_edit_report_with_status_valid(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $report = $this->makeReport(['status' => 2]);

        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(5)],
        ]);

        $res->assertStatus(403)
            ->assertJson(['message' => 'Laporan yang sudah valid tidak dapat diubah.']);
        $this->assertNull($report->fresh()->updated_by);
    }

    public function test_cannot_edit_report_when_newer_report_exists_for_store(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $old = $this->makeReport(['date' => now()->subYears(5)->toDateString()]);
        $new = $this->makeReport(); // default +5 tahun

        // Laporan lama terkunci...
        $res = $this->putJson("/storage-stocks/{$old->id}", [
            'items' => [$this->payloadItem(5)],
        ]);
        $res->assertStatus(403)
            ->assertJson(['message' => 'Hanya laporan terakhir per gudang yang dapat diedit.']);

        // ...laporan terakhir store yang sama tetap bisa diedit.
        $res = $this->putJson("/storage-stocks/{$new->id}", [
            'items' => [$this->payloadItem(5)],
        ]);
        $res->assertOk();
    }

    public function test_backdated_report_with_bigger_id_does_not_block(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        // A dibuat lebih dulu (id lebih kecil) tetapi tanggalnya lebih baru.
        $a = $this->makeReport(['date' => now()->addYears(5)->toDateString()]);
        // B dibuat belakangan (id lebih besar) tetapi backdated.
        $b = $this->makeReport(['date' => now()->addYears(4)->toDateString()]);

        // B bukan laporan terakhir (tanggal lebih lama) walau id lebih besar.
        $res = $this->putJson("/storage-stocks/{$b->id}", [
            'items' => [$this->payloadItem(5)],
        ]);
        $res->assertStatus(403);

        // A tetap yang terakhir → boleh edit.
        $res = $this->putJson("/storage-stocks/{$a->id}", [
            'items' => [$this->payloadItem(5)],
        ]);
        $res->assertOk();
    }

    public function test_show_includes_can_be_edited_and_updater(): void
    {
        $editor = $this->userWithRole('storage-staff');
        $report = $this->makeReport();

        Sanctum::actingAs($editor);
        $res = $this->putJson("/storage-stocks/{$report->id}", [
            'items' => [$this->payloadItem(9)],
        ]);
        $res->assertOk();

        $res = $this->getJson("/storage-stocks/{$report->id}");
        $res->assertOk()
            ->assertJsonPath('data.can_be_edited', true)
            ->assertJsonPath('data.updater.id', $editor->id);
    }

    public function test_show_can_be_edited_false_for_valid_status(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $report = $this->makeReport(['status' => 2]);

        $res = $this->getJson("/storage-stocks/{$report->id}");

        $res->assertOk()->assertJsonPath('data.can_be_edited', false);
    }

    public function test_show_can_be_edited_false_when_not_latest(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $this->makeReport(); // laporan terakhir (+5 tahun)
        $old = $this->makeReport(['date' => now()->subYears(5)->toDateString()]);

        $res = $this->getJson("/storage-stocks/{$old->id}");

        $res->assertOk()->assertJsonPath('data.can_be_edited', false);
    }

    public function test_show_can_be_edited_false_for_role_without_access(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $report = $this->makeReport();

        $res = $this->getJson("/storage-stocks/{$report->id}");

        $res->assertOk()->assertJsonPath('data.can_be_edited', false);
    }

    public function test_index_includes_can_be_edited_flag(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $new = $this->makeReport();
        $old = $this->makeReport(['date' => now()->subYears(5)->toDateString()]);

        $res = $this->getJson('/storage-stocks?per_page=50');

        $res->assertOk();
        $rows = collect($res->json('data'));
        $newRow = $rows->firstWhere('id', $new->id);
        $oldRow = $rows->firstWhere('id', $old->id);

        $this->assertNotNull($newRow, 'laporan terakhir harus muncul di page 1 (date desc)');
        $this->assertNotNull($oldRow);
        $this->assertTrue($newRow['can_be_edited']);
        $this->assertFalse($oldRow['can_be_edited']);
    }
}
