<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminConfigurationController extends Controller
{
    public function submittedVouchers(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);

        return response()->json(
            DB::table('vouchers')
                ->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')
                ->where('vouchers.organization_id', $org)
                ->where('vouchers.status', '!=', 'open')
                ->orderByDesc('vouchers.date_key')
                ->orderBy('outlets.name')
                ->limit(250)
                ->get([
                    'vouchers.id', 'vouchers.outlet_id', 'outlets.name as outlet_name',
                    'vouchers.date_key', 'vouchers.status', 'vouchers.total_sales_minor',
                    'vouchers.total_expenses_minor', 'vouchers.created_at', 'vouchers.posted_at',
                ])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'outletId' => $row->outlet_id,
                    'outletName' => $row->outlet_name,
                    'dateKey' => $row->date_key,
                    'status' => $row->status,
                    'totalSalesMinor' => (int) ($row->total_sales_minor ?? 0),
                    'totalExpensesMinor' => (int) ($row->total_expenses_minor ?? 0),
                    'submittedAt' => $row->posted_at ?? $row->created_at,
                ]),
        );
    }

    public function submittedVoucher(Request $request, string $org, string $voucher): JsonResponse
    {
        $this->admin($request, $org);
        $row = DB::table('vouchers')
            ->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')
            ->where('vouchers.organization_id', $org)
            ->where('vouchers.id', $voucher)
            ->select('vouchers.*', 'outlets.name as outlet_name', 'outlets.address as outlet_address')
            ->first();
        abort_unless($row, 404);
        abort_if($row->status === 'open', 409, 'Only submitted vouchers are available here.');
        $submittedAt = DB::table('audit_events')
            ->where('voucher_id', $voucher)
            ->where('action', 'VOUCHER_SUBMITTED_FOR_REVIEW')
            ->max('created_at');

        return response()->json([
            'id' => $row->id,
            'outletId' => $row->outlet_id,
            'outletName' => $row->outlet_name,
            'outletAddress' => $row->outlet_address,
            'dateKey' => $row->date_key,
            'status' => $row->status,
            'createdAt' => $row->created_at ? CarbonImmutable::parse($row->created_at)->toIso8601String() : null,
            'submittedAt' => $submittedAt ? CarbonImmutable::parse($submittedAt)->toIso8601String() : null,
            'approvedAt' => in_array($row->status, ['posted', 'variance'], true) && $row->posted_at ? CarbonImmutable::parse($row->posted_at)->toIso8601String() : null,
            'openingFloatMinor' => (int) $row->opening_float_minor,
            'cashSalesMinor' => (int) $row->cash_sales_minor,
            'cardSalesMinor' => (int) $row->card_sales_minor,
            'countedCashMinor' => $row->counted_cash_minor === null ? null : (int) $row->counted_cash_minor,
            'varianceReason' => $row->variance_reason,
            'otherVarianceReason' => $row->other_variance_reason,
            'expenses' => DB::table('expenses')->where('voucher_id', $voucher)->orderBy('created_at')->get()->map(fn ($expense) => [
                'id' => $expense->id,
                'description' => $expense->description,
                'categoryId' => $expense->category_id,
                'paymentMethod' => $expense->payment_method,
                'amountMinor' => (int) $expense->amount_minor,
                'receiptId' => $expense->receipt_path,
                'ocrSupplier' => $expense->ocr_supplier,
                'ocrTotalMinor' => $expense->ocr_total_minor === null ? null : (int) $expense->ocr_total_minor,
                'ocrCategoryId' => $expense->ocr_category_id,
                'ocrRawText' => $expense->ocr_raw_text,
            ]),
        ]);
    }

    public function destroySubmittedVoucher(Request $request, string $org, string $voucher): JsonResponse
    {
        $this->admin($request, $org);
        $receiptPaths = DB::table('expenses')
            ->where('voucher_id', $voucher)
            ->whereNotNull('receipt_path')
            ->pluck('receipt_path')
            ->filter()
            ->all();

        DB::transaction(function () use ($org, $voucher) {
            $row = DB::table('vouchers')
                ->where('organization_id', $org)
                ->where('id', $voucher)
                ->lockForUpdate()
                ->first();
            abort_unless($row, 404);
            abort_if($row->status === 'open', 409, 'Open vouchers cannot be deleted here.');

            DB::table('opening_floats')
                ->where('outlet_id', $row->outlet_id)
                ->where('source_voucher_id', $voucher)
                ->update([
                    'amount_minor' => $row->opening_float_minor,
                    'source_voucher_id' => null,
                    'effective_date_key' => $row->date_key,
                    'updated_at' => now(),
                ]);
            DB::table('vouchers')->where('id', $voucher)->delete();
        });

        foreach ($receiptPaths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json(['ok' => true]);
    }

    public function index(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);

        return response()->json([
            'categories' => DB::table('expense_categories')->where('organization_id', $org)->orderBy('sort_order')->orderBy('name')->get()->map(fn ($row) => $this->category($row)),
            'outlets' => DB::table('outlets')->where('organization_id', $org)->orderBy('name')->get()->map(fn ($row) => $this->outlet($row)),
        ]);
    }

    public function storeCategory(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);
        $data = $this->categoryData($request);
        $base = Str::slug($data['name'], '_');
        abort_if($base === '', 422, 'Enter a category name.');
        $id = 'cat_'.$base;
        $suffix = 2;
        while (DB::table('expense_categories')->where('id', $id)->exists()) {
            $id = 'cat_'.$base.'_'.$suffix++;
        }
        DB::table('expense_categories')->insert(['id' => $id, 'organization_id' => $org] + $data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->category(DB::table('expense_categories')->find($id)), 201);
    }

    public function updateCategory(Request $request, string $org, string $category): JsonResponse
    {
        $this->admin($request, $org);
        abort_unless(DB::table('expense_categories')->where(['id' => $category, 'organization_id' => $org])->exists(), 404);
        $data = $this->categoryData($request) + ['active' => $request->boolean('active', true), 'updated_at' => now()];
        DB::table('expense_categories')->where('id', $category)->update($data);

        return response()->json($this->category(DB::table('expense_categories')->find($category)));
    }

    public function storeOutlet(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);
        $data = $this->outletData($request, $org);
        $openingFloatMinor = $data['openingFloatMinor'];
        unset($data['openingFloatMinor']);
        $id = 'outlet_'.Str::slug($data['code'], '_');
        abort_if(DB::table('outlets')->where('id', $id)->exists(), 422, 'That outlet code is already used.');
        DB::transaction(function () use ($id, $org, $data, $openingFloatMinor) {
            DB::table('outlets')->insert(['id' => $id, 'organization_id' => $org] + $data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('opening_floats')->insert(['outlet_id' => $id, 'amount_minor' => $openingFloatMinor, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json($this->outlet(DB::table('outlets')->find($id)), 201);
    }

    public function updateOutlet(Request $request, string $org, string $outlet): JsonResponse
    {
        $this->admin($request, $org);
        abort_unless(DB::table('outlets')->where(['id' => $outlet, 'organization_id' => $org])->exists(), 404);
        $data = $this->outletData($request, $org, $outlet);
        $openingFloatMinor = $data['openingFloatMinor'];
        unset($data['openingFloatMinor']);
        $data += ['active' => $request->boolean('active', true), 'updated_at' => now()];
        DB::transaction(function () use ($org, $outlet, $data, $openingFloatMinor) {
            DB::table('outlets')->where('id', $outlet)->update($data);
            DB::table('opening_floats')->updateOrInsert(
                ['outlet_id' => $outlet],
                ['amount_minor' => $openingFloatMinor, 'source_voucher_id' => null, 'effective_date_key' => null, 'updated_at' => now(), 'created_at' => now()],
            );

            // A voucher copies its float when it is created. Keep today's
            // editable voucher in sync; submitted vouchers stay immutable.
            $timezone = DB::table('organizations')->where('id', $org)->value('timezone') ?: config('app.timezone');
            DB::table('vouchers')->where([
                'organization_id' => $org,
                'outlet_id' => $outlet,
                'date_key' => CarbonImmutable::now($timezone)->format('Ymd'),
                'status' => 'open',
            ])->update(['opening_float_minor' => $openingFloatMinor, 'updated_at' => now()]);
        });

        return response()->json($this->outlet(DB::table('outlets')->find($outlet)));
    }

    private function categoryData(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'accountCode' => ['required', 'string', 'max:100'], 'accountName' => ['required', 'string', 'max:255'], 'sortOrder' => ['nullable', 'integer', 'min:0']]);

        return ['name' => $data['name'], 'account_code' => $data['accountCode'], 'account_name' => $data['accountName'], 'sort_order' => (int) ($data['sortOrder'] ?? 0)];
    }

    private function outletData(Request $request, string $org, ?string $current = null): array
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', Rule::unique('outlets', 'code')->where(fn ($q) => $q->where('organization_id', $org))->ignore($current, 'id')], 'name' => ['required', 'string', 'max:255'], 'managerName' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'], 'openingFloatMinor' => ['required', 'integer', 'min:0']]);

        return ['code' => $data['code'], 'name' => $data['name'], 'manager_name' => $data['managerName'], 'address' => $data['address'] ?? null, 'openingFloatMinor' => (int) $data['openingFloatMinor']];
    }

    private function category(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'accountCode' => $row->account_code, 'accountName' => $row->account_name, 'sortOrder' => (int) $row->sort_order, 'active' => (bool) $row->active];
    }

    private function outlet(object $row): array
    {
        return ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'managerName' => $row->manager_name, 'address' => $row->address, 'openingFloatMinor' => (int) (DB::table('opening_floats')->where('outlet_id', $row->id)->value('amount_minor') ?? 0), 'active' => (bool) $row->active];
    }

    private function admin(Request $request, string $org): void
    {
        abort_unless(DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $request->user()->id, 'role' => 'admin', 'active' => true])->exists(), 403, 'Administrator access is required.');
    }
}
