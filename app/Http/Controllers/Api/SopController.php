<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sop;
use App\Models\SopCategory;
use Illuminate\Http\Request;

/**
 * Penyaji konten SOP (read-only) untuk mobile: kategori aktif, daftar SOP
 * published, dan detail beserta langkah-langkahnya.
 */
class SopController extends Controller
{
    /**
     * Kategori aktif + jumlah SOP published di dalamnya.
     */
    public function categories(Request $request)
    {
        $categories = SopCategory::active()
            ->withCount(['sops' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Daftar SOP published (terpaginasi), opsional difilter kategori dan
     * dicari pada judul.
     */
    public function index(Request $request)
    {
        $perPage = max(1, (int) $request->input('per_page', 15));
        $categoryId = $request->input('category_id');
        $q = trim((string) $request->input('q', ''));

        $query = Sop::published()
            ->with('category:id,name,icon')
            ->withCount('steps')
            ->orderBy('sort_order')
            ->orderBy('title');

        if ($categoryId !== null && $categoryId !== '') {
            $query->where('sop_category_id', (int) $categoryId);
        }
        if ($q !== '') {
            $query->where('title', 'like', "%{$q}%");
        }

        $sops = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $sops->items(),
            'meta' => [
                'current_page' => $sops->currentPage(),
                'last_page' => $sops->lastPage(),
                'per_page' => $sops->perPage(),
                'total' => $sops->total(),
            ],
        ]);
    }

    /**
     * Detail SOP + langkah terurut; hanya SOP published yang tersaji.
     */
    public function show(Request $request, int $id)
    {
        $sop = Sop::published()
            ->with(['category:id,name,icon', 'steps'])
            ->withCount('steps')
            ->find($id);

        abort_if($sop === null, 404, 'SOP tidak ditemukan.');

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $sop,
        ]);
    }
}
