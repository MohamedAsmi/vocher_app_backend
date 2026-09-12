<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'deviceName' => ['required', 'string', 'max:100']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }
        $role = $this->role($user->id);
        if ($role === null) {
            throw ValidationException::withMessages(['email' => ['This account is inactive.']]);
        }

        return response()->json([
            'token' => $user->createToken($data['deviceName'])->plainTextToken,
            'user' => $user,
            'role' => $role,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'role' => $this->role($request->user()->id),
            'outlets' => DB::table('outlet_user')
                ->join('outlets', 'outlets.id', '=', 'outlet_user.outlet_id')
                ->join('organization_user', function ($join) {
                    $join->on('organization_user.organization_id', '=', 'outlets.organization_id')
                        ->where('organization_user.user_id', '=', request()->user()->id);
                })
                ->where('outlet_user.user_id', $request->user()->id)
                ->where('outlet_user.active', true)
                ->where('outlets.active', true)
                ->where('organization_user.active', true)
                ->orderBy('outlets.name')
                ->get(['outlets.id', 'outlets.name']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function role(int $userId): ?string
    {
        return DB::table('organization_user')
            ->where('user_id', $userId)
            ->where('active', true)
            ->value('role');
    }
}
