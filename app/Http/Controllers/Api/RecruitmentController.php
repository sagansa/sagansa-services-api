<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicantDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RecruitmentController extends Controller
{
    /**
     * Ambil detail profil (data pribadi + rekening) milik user login.
     * Data berada di DB recruitment (mysql_recruitment) lewat relasi
     * ApplicantDetail milik User.
     */
    public function getDetails(Request $request)
    {
        $user = $request->user();
        $details = ApplicantDetail::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'details' => $details,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * Update detail profil.
     *
     * Aturan kunci (lock):
     * - Bila status ∈ submitted/accepted/reviewed/rejected DAN join_date sudah
     *   terisi, user HANYA boleh mengubah field rekening:
     *   bank_account_name, bank_account_number, bank_name.
     * - Bila status sudah disubmit tapi join_date belum terisi, seluruh
     *   profil terkunci (tidak bisa ubah apa pun).
     * - Bank account boleh diubah kapanpun (sesuai kebijakan mobile).
     */
    public function updateDetails(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'nickname' => 'nullable|string|max:255',
            'is_experienced' => 'nullable|boolean',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'birth_place' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'nik' => 'nullable|string|max:20',
            'religion' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'children_count' => 'nullable|integer',
            'education_level' => 'nullable|string|max:255',
            'education_major' => 'nullable|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'home_location' => 'nullable|string',
            'emergency_phone' => 'nullable|string|max:20',
            'emergency_name' => 'nullable|string|max:255',
            'driver_license' => 'nullable|string|max:255',
            'ktp_image' => 'nullable|image|mimes:jpeg,png,jpg|max:4096',
            'selfie_image' => 'nullable|image|mimes:jpeg,png,jpg|max:4096',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
        ]);

        $details = ApplicantDetail::where('user_id', $user->id)->first();

        // Field rekening selalu boleh diubah kapanpun.
        $bankKeys = ['bank_account_name', 'bank_account_number', 'bank_name'];

        if ($details && in_array($details->status, ['submitted', 'accepted', 'reviewed', 'rejected'])) {
            if ($details->join_date) {
                foreach ($request->keys() as $key) {
                    if (!in_array($key, $bankKeys)
                        && $request->has($key)
                        && $request->input($key) != ($details->$key ?? null)
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Profil terkunci. Hanya data rekening yang dapat diubah.',
                        ], 403);
                    }
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil sudah disubmit dan tidak dapat diubah.',
                ], 403);
            }
        }

        $data = $request->except(['ktp_image', 'selfie_image']);

        if ($request->hasFile('ktp_image')) {
            if ($details && $details->ktp_image) {
                Storage::disk('public')->delete($details->ktp_image);
            }
            $data['ktp_image'] = $request->file('ktp_image')->store('recruitment/ktp', 'public');
        }

        if ($request->hasFile('selfie_image')) {
            if ($details && $details->selfie_image) {
                Storage::disk('public')->delete($details->selfie_image);
            }
            $data['selfie_image'] = $request->file('selfie_image')->store('recruitment/selfie', 'public');
        }

        // Handle empty children_count
        if (isset($data['children_count']) && ($data['children_count'] === '' || $data['children_count'] === null)) {
            $data['children_count'] = 0;
        }

        $details = ApplicantDetail::updateOrCreate(
            ['user_id' => $user->id],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'details' => $details,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * Hapus foto KTP atau foto diri (selfie).
     */
    public function deleteImage(Request $request)
    {
        $user = $request->user();
        $type = $request->input('type');

        $details = ApplicantDetail::where('user_id', $user->id)->first();
        if ($details && $this->isLocked($details)) {
            return response()->json([
                'success' => false,
                'message' => 'Profil sudah disubmit/terkunci dan tidak dapat diubah.',
            ], 403);
        }

        if ($details && in_array($type, ['ktp_image', 'selfie_image'])) {
            if ($details->$type) {
                Storage::disk('public')->delete($details->$type);
            }
            $details->update([$type => null]);
            return response()->json([
                'success' => true,
                'message' => 'Foto berhasil dihapus.',
                'details' => $details,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Permintaan tidak valid.',
        ], 400);
    }

    /**
     * Tentukan apakah profil terkunci (field non-rekening tidak bisa diubah).
     */
    private function isLocked(?ApplicantDetail $details): bool
    {
        if (!$details) {
            return false;
        }

        if (in_array($details->status, ['submitted', 'accepted', 'reviewed', 'rejected'])) {
            return (bool) $details->join_date;
        }

        return false;
    }

    /**
     * ADMIN: List seluruh profil pelamar beserta status & info kunci.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status');

        $query = ApplicantDetail::with('user')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('id', 'desc');

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function (ApplicantDetail $d) {
            return [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'name' => $d->user?->name,
                'email' => $d->user?->email,
                'status' => $d->status,
                'join_date' => $d->join_date,
                'locked' => $this->isLocked($d),
                'nickname' => $d->nickname,
                'bank_account_name' => $d->bank_account_name,
                'bank_account_number' => $d->bank_account_number,
                'bank_name' => $d->bank_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * ADMIN: Detail 1 profil (data pribadi + rekening + info user).
     */
    public function show(Request $request, $id)
    {
        $details = ApplicantDetail::with('user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'details' => $details,
            'user' => $details->user
                ? ['id' => $details->user->id, 'name' => $details->user->name, 'email' => $details->user->email]
                : null,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * ADMIN: Detail profil berdasarkan user id — dipakai mis. untuk menampilkan
     * rekening pegawai penerima payment receipt gaji harian (receipt gaji
     * tidak memakai QRIS supplier).
     */
    public function showByUser(Request $request, $userId)
    {
        $details = ApplicantDetail::with('user')
            ->where('user_id', $userId)
            ->first();

        if (!$details) {
            return response()->json([
                'success' => false,
                'message' => 'Profil untuk user tersebut tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'details' => $details,
            'user' => $details->user
                ? ['id' => $details->user->id, 'name' => $details->user->name, 'email' => $details->user->email]
                : null,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * ADMIN: Ubah status profil (kunci / buka kunci).
     *
     * - status = 'draft'  -> buka kunci (user boleh ubah semua field).
     * - status = 'submitted' + join_date = hari ini -> kunci
     *   (hanya rekening yg bisa diubah user).
     */
    public function setStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:draft,submitted',
        ]);

        $details = ApplicantDetail::findOrFail($id);

        if ($request->status === 'submitted') {
            $details->update([
                'status' => 'submitted',
                'join_date' => $details->join_date ?? now()->toDateString(),
            ]);
        } else {
            // draft: buka kunci (biarkan join_date tetap, status cukup draft)
            $details->update(['status' => 'draft']);
        }

        return response()->json([
            'success' => true,
            'message' => $request->status === 'submitted'
                ? 'Profil dikunci (hanya rekening yang dapat diubah).'
                : 'Profil dibuka (semua data dapat diubah).',
            'details' => $details,
            'locked' => $this->isLocked($details),
        ]);
    }
}
