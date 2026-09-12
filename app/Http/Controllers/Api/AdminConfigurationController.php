<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminConfigurationController extends Controller
{
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
        while (DB::table('expense_categories')->where('id', $id)->exists()) $id = 'cat_'.$base.'_'. $suffix++;
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
        $id = 'outlet_'.Str::slug($data['code'], '_');
        abort_if(DB::table('outlets')->where('id', $id)->exists(), 422, 'That outlet code is already used.');
        DB::table('outlets')->insert(['id' => $id, 'organization_id' => $org] + $data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('opening_floats')->insert(['outlet_id' => $id, 'amount_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->outlet(DB::table('outlets')->find($id)), 201);
    }

    public function updateOutlet(Request $request, string $org, string $outlet): JsonResponse
    {
        $this->admin($request, $org);
        abort_unless(DB::table('outlets')->where(['id' => $outlet, 'organization_id' => $org])->exists(), 404);
        $data = $this->outletData($request, $org, $outlet) + ['active' => $request->boolean('active', true), 'updated_at' => now()];
        DB::table('outlets')->where('id', $outlet)->update($data);
        return response()->json($this->outlet(DB::table('outlets')->find($outlet)));
    }

    private function categoryData(Request $request): array { $data = $request->validate(['name' => ['required','string','max:255'], 'accountCode' => ['required','string','max:100'], 'accountName' => ['required','string','max:255'], 'sortOrder' => ['nullable','integer','min:0']]); return ['name' => $data['name'], 'account_code' => $data['accountCode'], 'account_name' => $data['accountName'], 'sort_order' => (int) ($data['sortOrder'] ?? 0)]; }
    private function outletData(Request $request, string $org, ?string $current = null): array { $data = $request->validate(['code' => ['required','string','max:50', Rule::unique('outlets', 'code')->where(fn ($q) => $q->where('organization_id', $org))->ignore($current, 'id')], 'name' => ['required','string','max:255'], 'managerName' => ['required','string','max:255'], 'address' => ['nullable','string','max:2000']]); return ['code' => $data['code'], 'name' => $data['name'], 'manager_name' => $data['managerName'], 'address' => $data['address'] ?? null]; }
    private function category(object $row): array { return ['id' => $row->id, 'name' => $row->name, 'accountCode' => $row->account_code, 'accountName' => $row->account_name, 'sortOrder' => (int) $row->sort_order, 'active' => (bool) $row->active]; }
    private function outlet(object $row): array { return ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'managerName' => $row->manager_name, 'address' => $row->address, 'active' => (bool) $row->active]; }
    private function admin(Request $request, string $org): void { abort_unless(DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $request->user()->id, 'role' => 'admin', 'active' => true])->exists(), 403, 'Administrator access is required.'); }
}
