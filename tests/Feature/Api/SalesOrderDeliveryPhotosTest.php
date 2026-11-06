<?php

namespace Tests\Feature\Api;

use App\Contracts\ImageStorageContract;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/{id}/delivery-photos (updateDeliveryPhotos):
 *  - Khusus admin & super_admin (supervisor TIDAK termasuk) — lihat spec
 *    2026-09-04-direct-delivery-photo-admin-edit-design.md.
 *  - Scope order direct saja (for=1); order online (for=3) → 404.
 *  - Tanpa batasan status order — foto bisa diganti kapan saja, termasuk
 *    setelah terkirim (3)/valid (2)/dikembalikan (6).
 *  - Minimal 1 foto; foto lama yang tidak ada di list baru di-delete dari
 *    storage.
 */
class SalesOrderDeliveryPhotosTest extends TestCase
{
    /** @var array<int> id order yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->trackedOrderIds)) {
            DB::table('sales_orders')->whereIn('id', $this->trackedOrderIds)->delete();
        }
        parent::tearDown();
    }

    /**
     * Ganti binding ImageStorageContract dengan test double yang merekam
     * path yang di-delete (endpoint memanggil delete utk foto lama yang
     * dibuang; jangan menyentuh img service sungguhan dari test).
     */
    private function fakeImageStorage(): object
    {
        $fake = new class implements ImageStorageContract
        {
            /** @var array<string> */
            public array $deleted = [];

            public function upload(\Illuminate\Http\UploadedFile $file, string $directory = ''): ?string
            {
                return 'images/Delivery/uploaded.jpg';
            }

            public function delete(?string $path): void
            {
                $this->deleted[] = (string) $path;
            }
        };
        $this->app->instance(ImageStorageContract::class, $fake);

        return $fake;
    }

    /**
     * Buat order direct (for=1) dengan foto lama (JSON array path).
     */
    private function makeDirectOrder(int $deliveryStatus, array $oldPaths): int
    {
        $id = DB::table('sales_orders')->insertGetId([
            'for' => '1',
            'store_id' => Store::factory()->create()->id,
            'receipt_no' => 'DR-' . strtoupper(uniqid()),
            'delivery_date' => now()->toDateString(),
            'payment_status' => '2',
            'delivery_status' => (string) $deliveryStatus,
            'shipping_cost' => 0,
            'total_price' => 1000,
            'image_delivery' => json_encode($oldPaths),
            'ordered_by_id' => User::factory()->create()->id,
        ]);
        $this->trackedOrderIds[] = $id;

        return $id;
    }

    public function test_admin_bisa_mengganti_foto_order_sudah_dikirim(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('admin'));
        $storage = $this->fakeImageStorage();

        // Kasus yang dilaporkan: order sudah "Sudah Dikirim" (3).
        $orderId = $this->makeDirectOrder(3, [
            'images/Delivery/keep.jpg',
            'images/Delivery/drop.jpg',
        ]);

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => [
                'images/Delivery/keep.jpg',
                'images/Delivery/new.jpg',
            ],
        ]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.image_delivery_paths', [
                'images/Delivery/keep.jpg',
                'images/Delivery/new.jpg',
            ]);
        $this->assertCount(2, $res->json('data.image_delivery_urls'));

        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'image_delivery' => json_encode([
                'images/Delivery/keep.jpg',
                'images/Delivery/new.jpg',
            ]),
            'delivery_status' => '3', // status tidak berubah
        ]);
        // Hanya foto lama yang dibuang yang di-delete dari storage.
        $this->assertSame(['images/Delivery/drop.jpg'], $storage->deleted);
    }

    public function test_admin_bisa_mengganti_foto_order_valid_dan_dikembalikan(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('admin'));
        $this->fakeImageStorage();

        foreach ([2, 6] as $status) {
            $orderId = $this->makeDirectOrder($status, ['images/Delivery/old.jpg']);

            $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
                'image_delivery' => ['images/Delivery/replacement.jpg'],
            ]);

            $res->assertOk()->assertJsonPath('success', true)
                ->assertJsonPath('data.delivery_status', $status);
            $this->assertDatabaseHas('sales_orders', [
                'id' => $orderId,
                'image_delivery' => json_encode(['images/Delivery/replacement.jpg']),
            ]);
        }
    }

    public function test_super_admin_bisa_mengganti_foto(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('super_admin'));
        $this->fakeImageStorage();

        $orderId = $this->makeDirectOrder(3, ['images/Delivery/old.jpg']);

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => ['images/Delivery/new.jpg'],
        ]);

        $res->assertOk()->assertJsonPath('success', true);
    }

    public function test_user_biasa_ditolak_403(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeImageStorage();

        $orderId = $this->makeDirectOrder(3, ['images/Delivery/old.jpg']);

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => ['images/Delivery/new.jpg'],
        ]);

        $res->assertStatus(403)->assertJsonPath('success', false);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'image_delivery' => json_encode(['images/Delivery/old.jpg']),
        ]);
    }

    public function test_supervisor_ditolak_403(): void
    {
        // Supervisor TIDAK termasuk (beda dari loadAdminRole mobile).
        Role::firstOrCreate(['name' => 'supervisor']);
        Sanctum::actingAs(User::factory()->create()->assignRole('supervisor'));
        $this->fakeImageStorage();

        $orderId = $this->makeDirectOrder(3, ['images/Delivery/old.jpg']);

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => ['images/Delivery/new.jpg'],
        ]);

        $res->assertStatus(403)->assertJsonPath('success', false);
    }

    public function test_order_tidak_ditemukan_404(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('admin'));

        $res = $this->postJson('/sales-orders/999999999/delivery-photos', [
            'image_delivery' => ['images/Delivery/new.jpg'],
        ]);

        $res->assertStatus(404)->assertJsonPath('success', false);
    }

    public function test_order_online_ditolak_404(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('admin'));

        $orderId = DB::table('sales_orders')->insertGetId([
            'for' => '3',
            'store_id' => Store::factory()->create()->id,
            'receipt_no' => 'ON-' . strtoupper(uniqid()),
            'delivery_date' => now()->toDateString(),
            'payment_status' => '2',
            'delivery_status' => '3',
            'shipping_cost' => 0,
            'total_price' => 1000,
            'ordered_by_id' => User::factory()->create()->id,
        ]);
        $this->trackedOrderIds[] = $orderId;

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => ['images/Delivery/new.jpg'],
        ]);

        $res->assertStatus(404)->assertJsonPath('success', false);
    }

    public function test_daftar_foto_kosong_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('admin'));
        $this->fakeImageStorage();

        $orderId = $this->makeDirectOrder(3, ['images/Delivery/old.jpg']);

        $res = $this->postJson("/sales-orders/{$orderId}/delivery-photos", [
            'image_delivery' => [],
        ]);

        $res->assertStatus(422)->assertJsonPath('success', false);
        // Foto lama tidak boleh hilang.
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'image_delivery' => json_encode(['images/Delivery/old.jpg']),
        ]);
    }
}
