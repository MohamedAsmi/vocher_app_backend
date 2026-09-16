<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookkeeperController extends Controller
{
    public function approve(Request $request, string $org, string $voucher): JsonResponse
    {
        return response()->json(DB::transaction(function () use ($request, $org, $voucher) {
            $row = DB::table('vouchers')
                ->where('organization_id', $org)
                ->where('id', $voucher)
                ->lockForUpdate()
                ->first();
            abort_unless($row, 404);
            $this->bookkeeper($request, $org, $row->outlet_id);
            abort_if($row->status !== 'pending_review', 409, 'Voucher is not pending review.');

            $status = (int) $row->variance_minor === 0 ? 'posted' : 'variance';
            DB::table('vouchers')->where('id', $voucher)->update([
                'status' => $status,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'voucher_id' => $voucher,
                'actor_id' => $request->user()->id,
                'action' => 'VOUCHER_APPROVED_AND_POSTED',
                'payload' => json_encode(['status' => $status]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->voucher($voucher);
        }, 3));
    }

    public function reviews(Request $request, string $org): JsonResponse
    {
        $dateKey = $request->validate(['dateKey' => ['required', 'date_format:Ymd']])['dateKey'];
        $role = DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $request->user()->id, 'active' => true])->value('role');
        abort_unless(in_array($role, ['admin', 'bookkeeper'], true), 403, 'Bookkeeper access is required.');
        $outlets = DB::table('outlets')->when($role !== 'admin', fn ($q) => $q->join('outlet_user', 'outlet_user.outlet_id', '=', 'outlets.id')->where('outlet_user.user_id', $request->user()->id)->where('outlet_user.active', true))->where('outlets.organization_id', $org)->select('outlets.*')->get();

        return response()->json($outlets->map(function ($outlet) use ($dateKey) {
            $voucher = DB::table('vouchers')->where(['outlet_id' => $outlet->id, 'date_key' => $dateKey])->whereIn('status', ['pending_review', 'posted', 'variance'])->first();

            return [
                'outletId' => $outlet->id,
                'outletName' => $outlet->name,
                'managerName' => $outlet->manager_name,
                'voucher' => $voucher ? $this->voucher($voucher->id) : null,
                'followUpOpen' => $voucher ? DB::table('follow_ups')->where(['voucher_id' => $voucher->id, 'status' => 'open'])->exists() : false,
            ];
        }));
    }

    public function followUp(Request $request, string $org, string $voucher): JsonResponse
    {
        $data = $request->validate(['open' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $row = DB::table('vouchers')->where('organization_id', $org)->find($voucher);
        abort_unless($row, 404);
        $role = DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $request->user()->id, 'active' => true])->value('role');
        abort_unless(in_array($role, ['admin', 'bookkeeper'], true), 403);
        DB::table('follow_ups')->updateOrInsert(['voucher_id' => $voucher], ['outlet_id' => $row->outlet_id, 'flagged_by' => $request->user()->id, 'status' => $data['open'] ? 'open' : 'resolved', 'reason' => $data['reason'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function bookkeeper(Request $request, string $org, string $outlet): void
    {
        $role = DB::table('organization_user')->where([
            'organization_id' => $org,
            'user_id' => $request->user()->id,
            'active' => true,
        ])->value('role');
        abort_unless(in_array($role, ['admin', 'bookkeeper'], true), 403, 'Bookkeeper access is required.');
        if ($role !== 'admin') {
            abort_unless(DB::table('outlet_user')->where([
                'outlet_id' => $outlet,
                'user_id' => $request->user()->id,
                'active' => true,
            ])->exists(), 403, 'This outlet is not assigned to the bookkeeper.');
        }
    }

    private function voucher(string $id): array
    {
        $row = DB::table('vouchers')
            ->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')
            ->where('vouchers.id', $id)
            ->select('vouchers.*', 'outlets.name as outlet_name')
            ->first();

        return [
            'id' => $row->id,
            'outletId' => $row->outlet_id,
            'outletName' => $row->outlet_name,
            'dateKey' => $row->date_key,
            'status' => $row->status,
            'createdAt' => $row->created_at,
            'approvedAt' => in_array($row->status, ['posted', 'variance'], true) ? $row->posted_at : null,
            'openingFloatMinor' => (int) $row->opening_float_minor,
            'cashSalesMinor' => (int) $row->cash_sales_minor,
            'cardSalesMinor' => (int) $row->card_sales_minor,
            'countedCashMinor' => $row->counted_cash_minor === null ? null : (int) $row->counted_cash_minor,
            'varianceReason' => $row->variance_reason,
            'otherVarianceReason' => $row->other_variance_reason,
            'expenses' => DB::table('expenses')->where('voucher_id', $id)->orderBy('created_at')->get()->map(fn ($expense) => [
                'id' => $expense->id,
                'description' => $expense->description,
                'categoryId' => $expense->category_id,
                'paymentMethod' => $expense->payment_method,
                'amountMinor' => (int) $expense->amount_minor,
                'receiptId' => $expense->receipt_path,
            ])->all(),
        ];
    }
}
