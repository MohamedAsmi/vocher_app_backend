<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);

        return response()->json([
            'users' => DB::table('organization_user')
                ->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.organization_id', $org)
                ->orderBy('users.name')
                ->select('users.id', 'users.name', 'users.email', 'organization_user.role', 'organization_user.active')
                ->get()
                ->map(fn ($user) => $this->mapUser($user, $org)),
            'outlets' => DB::table('outlets')
                ->where('organization_id', $org)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, string $org): JsonResponse
    {
        $this->admin($request, $org);
        $data = $this->validated($request, $org);

        $user = DB::transaction(function () use ($data, $org) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            DB::table('organization_user')->insert([
                'organization_id' => $org,
                'user_id' => $user->id,
                'role' => $data['role'],
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncOutlets($user->id, $org, $data['outletIds'] ?? []);

            return $user;
        });

        return response()->json($this->findUser($user->id, $org), 201);
    }

    public function update(Request $request, string $org, User $user): JsonResponse
    {
        $this->admin($request, $org);
        abort_unless(DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $user->id])->exists(), 404);
        $data = $this->validated($request, $org, $user->id, false);
        abort_if($request->user()->id === $user->id && ($data['active'] ?? true) === false, 422, 'You cannot deactivate your own account.');

        DB::transaction(function () use ($data, $org, $user) {
            $active = $data['active'] ?? true;
            $user->name = $data['name'];
            $user->email = $data['email'];
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                $user->tokens()->delete();
            }
            $user->save();
            DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $user->id])->update([
                'role' => $data['role'],
                'active' => $active,
                'updated_at' => now(),
            ]);
            $this->syncOutlets($user->id, $org, $active ? ($data['outletIds'] ?? []) : []);
            if (! $active) {
                $user->tokens()->delete();
            }
        });

        return response()->json($this->findUser($user->id, $org));
    }

    public function destroy(Request $request, string $org, User $user): JsonResponse
    {
        $this->admin($request, $org);
        abort_if($request->user()->id === $user->id, 422, 'You cannot deactivate your own account.');
        abort_unless(DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $user->id])->exists(), 404);
        DB::table('organization_user')->where(['organization_id' => $org, 'user_id' => $user->id])->update(['active' => false, 'updated_at' => now()]);
        $organizationOutletIds = DB::table('outlets')->where('organization_id', $org)->pluck('id');
        DB::table('outlet_user')->where('user_id', $user->id)->whereIn('outlet_id', $organizationOutletIds)->update(['active' => false, 'updated_at' => now()]);
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, string $org, ?int $userId = null, bool $passwordRequired = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'outletManager', 'bookkeeper'])],
            'active' => ['sometimes', 'boolean'],
            'outletIds' => ['array'],
            'outletIds.*' => [
                'string',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true)),
            ],
        ]);
    }

    private function syncOutlets(int $userId, string $org, array $outletIds): void
    {
        $organizationOutletIds = DB::table('outlets')->where('organization_id', $org)->pluck('id');
        DB::table('outlet_user')->where('user_id', $userId)->whereIn('outlet_id', $organizationOutletIds)->delete();
        foreach (array_unique($outletIds) as $outletId) {
            DB::table('outlet_user')->insert([
                'outlet_id' => $outletId,
                'user_id' => $userId,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function findUser(int $userId, string $org): array
    {
        $user = DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $org)
            ->where('users.id', $userId)
            ->select('users.id', 'users.name', 'users.email', 'organization_user.role', 'organization_user.active')
            ->first();
        abort_unless($user, 404);

        return $this->mapUser($user, $org);
    }

    private function mapUser(object $user, string $org): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'outletIds' => DB::table('outlet_user')
                ->join('outlets', 'outlets.id', '=', 'outlet_user.outlet_id')
                ->where('outlet_user.user_id', $user->id)
                ->where('outlet_user.active', true)
                ->where('outlets.organization_id', $org)
                ->pluck('outlets.id'),
        ];
    }

    private function admin(Request $request, string $org): void
    {
        $role = DB::table('organization_user')->where([
            'organization_id' => $org,
            'user_id' => $request->user()->id,
            'active' => true,
        ])->value('role');
        abort_unless($role === 'admin', 403, 'Administrator access is required.');
    }
}
