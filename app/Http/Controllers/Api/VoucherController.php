<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoucherAccounting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function __construct(private VoucherAccounting $accounting) {}

    public function today(Request $request, string $org, string $outlet): JsonResponse
    {
        $this->manager($request, $org, $outlet);
        $organization = DB::table('organizations')->find($org);
        abort_unless($organization, 404, 'Organization not found.');
        $dateKey = CarbonImmutable::now($organization->timezone)->format('Ymd');
        $id = "{$outlet}_{$dateKey}";
        DB::transaction(function () use ($request, $org, $outlet, $dateKey, $id) {
            if (DB::table('vouchers')->where('id', $id)->lockForUpdate()->exists()) {
                return;
            }
            $float = DB::table('opening_floats')->where('outlet_id', $outlet)->value('amount_minor') ?? 0;
            DB::table('vouchers')->insert(['id' => $id, 'organization_id' => $org, 'outlet_id' => $outlet, 'date_key' => $dateKey, 'opening_float_minor' => $float, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['voucher_id' => $id, 'actor_id' => $request->user()->id, 'action' => 'VOUCHER_CREATED', 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json($this->voucher($id));
    }

    public function update(Request $request, string $org, string $voucher): JsonResponse
    {
        $row = DB::table('vouchers')->where('organization_id', $org)->find($voucher);
        abort_unless($row, 404);
        $this->manager($request, $org, $row->outlet_id);
        abort_if($row->status !== 'open', 409, 'Submitted vouchers are immutable.');
        $data = $request->validate([
            'cashSalesMinor' => ['required', 'integer', 'min:0'], 'cardSalesMinor' => ['required', 'integer', 'min:0'],
            'countedCashMinor' => ['nullable', 'integer', 'min:0'], 'varianceReason' => ['nullable', Rule::in(['rounding', 'tillFloatError', 'uncountedTip', 'other'])],
            'otherVarianceReason' => ['nullable', 'string', 'max:1000'], 'expenses' => ['required', 'array'], 'expenses.*.id' => ['required', 'uuid'],
            'expenses.*.description' => ['required', 'string', 'max:255'], 'expenses.*.categoryId' => ['required', 'string', Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
            'expenses.*.paymentMethod' => ['required', Rule::in(['cash', 'bankTransfer'])], 'expenses.*.amountMinor' => ['required', 'integer', 'min:1'],
            'expenses.*.receiptId' => ['nullable', 'string', 'max:2048'],
            'expenses.*.ocrSupplier' => ['nullable', 'string', 'max:255'],
            'expenses.*.ocrTotalMinor' => ['nullable', 'integer', 'min:0'],
            'expenses.*.ocrCategoryId' => ['nullable', 'string', 'max:255'],
            'expenses.*.ocrRawText' => ['nullable', 'string', 'max:20000'],
        ]);
        DB::transaction(function () use ($request, $voucher, $data) {
            $locked = DB::table('vouchers')->where('id', $voucher)->lockForUpdate()->first();
            abort_if($locked->status !== 'open', 409, 'Submitted vouchers are immutable.');
            DB::table('vouchers')->where('id', $voucher)->update(['cash_sales_minor' => $data['cashSalesMinor'], 'card_sales_minor' => $data['cardSalesMinor'], 'counted_cash_minor' => $data['countedCashMinor'] ?? null, 'variance_reason' => $data['varianceReason'] ?? null, 'other_variance_reason' => $data['otherVarianceReason'] ?? null, 'updated_at' => now()]);
            $ids = array_column($data['expenses'], 'id');
            DB::table('expenses')->where('voucher_id', $voucher)->when($ids, fn ($q) => $q->whereNotIn('id', $ids))->delete();
            foreach ($data['expenses'] as $expense) {
                $owner = DB::table('expenses')->where('id', $expense['id'])->value('voucher_id');
                abort_if($owner !== null && $owner !== $voucher, 403, 'Expense does not belong to this voucher.');
                DB::table('expenses')->updateOrInsert(
                    ['id' => $expense['id'], 'voucher_id' => $voucher],
                    ['category_id' => $expense['categoryId'], 'description' => $expense['description'], 'payment_method' => $expense['paymentMethod'], 'amount_minor' => $expense['amountMinor'], 'receipt_path' => $expense['receiptId'] ?? null, 'ocr_supplier' => $expense['ocrSupplier'] ?? null, 'ocr_total_minor' => $expense['ocrTotalMinor'] ?? null, 'ocr_category_id' => $expense['ocrCategoryId'] ?? null, 'ocr_raw_text' => $expense['ocrRawText'] ?? null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            DB::table('audit_events')->insert(['voucher_id' => $voucher, 'actor_id' => $request->user()->id, 'action' => 'VOUCHER_UPDATED', 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json($this->voucher($voucher));
    }

    public function submitForReview(Request $request, string $org, string $voucher): JsonResponse
    {
        $data = $request->validate(['operationId' => ['required', 'uuid']]);

        return response()->json(DB::transaction(function () use ($request, $org, $voucher, $data) {
            $done = DB::table('voucher_operations')->find($data['operationId']);
            if ($done) {
                return json_decode($done->result, true);
            }
            $row = DB::table('vouchers')->where('organization_id', $org)->where('id', $voucher)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->manager($request, $org, $row->outlet_id);
            abort_if($row->status !== 'open', 409, 'Voucher is already submitted.');
            abort_if($row->counted_cash_minor === null, 422, 'Count the till before submitting.');
            $mapped = $this->mapVoucher($row);
            $expenses = DB::table('expenses')->where('voucher_id', $voucher)->get()->map(fn ($e) => $this->mapExpense($e))->all();
            $categories = DB::table('expense_categories')->where('organization_id', $org)->get()->keyBy('id')->map(fn ($c) => (array) $c)->all();
            $totals = $this->accounting->totals($mapped, $expenses);
            abort_if($totals['varianceMinor'] !== 0 && ! $row->variance_reason, 422, 'A variance reason is required.');
            abort_if($totals['varianceMinor'] !== 0 && $row->variance_reason === 'other' && ! trim((string) $row->other_variance_reason), 422, 'An explanation is required for Other.');
            $journals = $this->accounting->journals($mapped, $expenses, $categories);
            abort_unless(collect($journals)->every(fn ($j) => $j['balanced']), 500, 'Generated journal is not balanced.');
            DB::table('vouchers')->where('id', $voucher)->update(['status' => 'pending_review', 'cash_expenses_minor' => $totals['cashExpensesMinor'], 'bank_expenses_minor' => $totals['bankExpensesMinor'], 'total_expenses_minor' => $totals['totalExpensesMinor'], 'total_sales_minor' => $totals['totalSalesMinor'], 'expected_cash_minor' => $totals['expectedCashMinor'], 'variance_minor' => $totals['varianceMinor'], 'posted_by' => $request->user()->id, 'posted_at' => now(), 'updated_at' => now()]);
            DB::table('journals')->where('voucher_id', $voucher)->delete();
            foreach ($journals as $journal) {
                foreach ($journal['lines'] as $i => $line) {
                    DB::table('journals')->insert(['voucher_id' => $voucher, 'type' => $journal['type'], 'line_number' => $i + 1, 'account_code' => $line['accountCode'], 'account_name' => $line['accountName'], 'debit_minor' => $line['debitMinor'], 'credit_minor' => $line['creditMinor'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('opening_floats')->updateOrInsert(['outlet_id' => $row->outlet_id], ['amount_minor' => $row->counted_cash_minor, 'source_voucher_id' => $voucher, 'effective_date_key' => CarbonImmutable::createFromFormat('Ymd', $row->date_key)->addDay()->format('Ymd'), 'updated_at' => now(), 'created_at' => now()]);
            DB::table('audit_events')->insert(['voucher_id' => $voucher, 'actor_id' => $request->user()->id, 'action' => 'VOUCHER_SUBMITTED_FOR_REVIEW', 'payload' => json_encode($totals), 'created_at' => now(), 'updated_at' => now()]);
            $result = $this->voucher($voucher);
            DB::table('voucher_operations')->insert(['id' => $data['operationId'], 'voucher_id' => $voucher, 'actor_id' => $request->user()->id, 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 3));
    }

    public function history(Request $request, string $org, string $outlet): JsonResponse
    {
        $this->member($request, $org, $outlet);
        $ids = DB::table('vouchers')->where('organization_id', $org)->where('outlet_id', $outlet)->whereIn('status', ['pending_review', 'posted', 'variance'])->orderByDesc('date_key')->pluck('id');

        return response()->json($ids->map(fn ($id) => $this->voucher($id)));
    }

    public function receipt(Request $request, string $org, string $voucher, string $expense): JsonResponse
    {
        $row = DB::table('vouchers')->where('organization_id', $org)->find($voucher);
        abort_unless($row, 404);
        $this->manager($request, $org, $row->outlet_id);
        abort_if($row->status !== 'open', 409, 'Submitted vouchers are immutable.');
        $request->validate(['receipt' => ['required', 'file', 'image', 'max:10240']]);
        $path = $request->file('receipt')->store("receipts/{$org}/{$voucher}", 'public');

        return response()->json(['receiptId' => $path, 'url' => Storage::disk('public')->url($path)]);
    }

    public function deleteReceipt(Request $request, string $org): JsonResponse
    {
        $data = $request->validate(['receiptId' => ['required', 'string', 'max:2048']]);
        $path = $data['receiptId'];
        abort_unless(str_starts_with($path, "receipts/{$org}/"), 403);
        $parts = explode('/', $path);
        $voucherId = $parts[2] ?? null;
        abort_unless($voucherId, 422, 'Invalid receipt path.');
        $voucher = DB::table('vouchers')->where('organization_id', $org)->find($voucherId);
        abort_unless($voucher, 404);
        $this->manager($request, $org, $voucher->outlet_id);
        abort_if($voucher->status !== 'open', 409, 'Submitted receipt evidence cannot be deleted.');
        Storage::disk('public')->delete($path);

        return response()->json(['ok' => true]);
    }

    private function voucher(string $id): array
    {
        $row = DB::table('vouchers')->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')->where('vouchers.id', $id)->select('vouchers.*', 'outlets.name as outlet_name', 'outlets.address as outlet_address')->first();
        abort_unless($row, 404);

        return $this->mapVoucher($row) + ['expenses' => DB::table('expenses')->where('voucher_id', $id)->orderBy('created_at')->get()->map(fn ($e) => $this->mapExpense($e))->all()];
    }

    private function mapVoucher(object $v): array
    {
        return ['id' => $v->id, 'outletId' => $v->outlet_id, 'outletName' => $v->outlet_name ?? '', 'outletAddress' => $v->outlet_address ?? null, 'dateKey' => $v->date_key, 'status' => $v->status, 'openingFloatMinor' => (int) $v->opening_float_minor, 'cashSalesMinor' => (int) $v->cash_sales_minor, 'cardSalesMinor' => (int) $v->card_sales_minor, 'countedCashMinor' => $v->counted_cash_minor === null ? null : (int) $v->counted_cash_minor, 'varianceReason' => $v->variance_reason, 'otherVarianceReason' => $v->other_variance_reason];
    }

    private function mapExpense(object $e): array
    {
        return ['id' => $e->id, 'description' => $e->description, 'categoryId' => $e->category_id, 'paymentMethod' => $e->payment_method, 'amountMinor' => (int) $e->amount_minor, 'receiptId' => $e->receipt_path, 'ocrSupplier' => $e->ocr_supplier, 'ocrTotalMinor' => $e->ocr_total_minor === null ? null : (int) $e->ocr_total_minor, 'ocrCategoryId' => $e->ocr_category_id, 'ocrRawText' => $e->ocr_raw_text];
    }

    private function manager(Request $r, string $org, string $outlet): void
    {
        $role = DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $r->user()->id, 'active' => true])->value('role');
        abort_unless(in_array($role, ['admin', 'outletManager'], true), 403);
        if ($role !== 'admin') {
            abort_unless(DB::table('outlet_user')->where(['outlet_id' => $outlet, 'user_id' => $r->user()->id, 'active' => true])->exists(), 403);
        }
    }

    private function member(Request $r, string $org, string $outlet): void
    {
        $role = DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $r->user()->id, 'active' => true])->value('role');
        abort_unless($role, 403);
        if ($role !== 'admin') {
            abort_unless(DB::table('outlet_user')->where(['outlet_id' => $outlet, 'user_id' => $r->user()->id, 'active' => true])->exists(), 403);
        }
    }
}
