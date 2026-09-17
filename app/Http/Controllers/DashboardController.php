<?php

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function loginForm(): View
    {
        return view('dashboard.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'The provided credentials are incorrect.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        if (! $this->isAdmin($request->user())) {
            Auth::logout();
            $request->session()->invalidate();

            return back()->withErrors(['email' => 'Administrator access is required.'])->onlyInput('email');
        }

        return redirect()->route('dashboard');
    }

    public function index(Request $request): View
    {
        $this->authorizeDashboard($request);
        $logs = ApiRequestLog::query()
            ->with('user:id,name,email')
            ->when($request->filled('status'), fn ($query) => $query->where('success', $request->string('status')->value() === 'success'))
            ->when($request->filled('path'), fn ($query) => $query->where('path', 'like', '%'.$request->string('path')->value().'%'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('dashboard.index', [
            'logs' => $logs,
            'users' => User::query()->select('id', 'name', 'email', 'created_at')->orderBy('name')->get(),
            'stats' => [
                'total' => ApiRequestLog::count(),
                'success' => ApiRequestLog::where('success', true)->count(),
                'failed' => ApiRequestLog::where('success', false)->count(),
            ],
        ]);
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeDashboard($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();

        return back()->with('status', "Password updated for {$user->email}.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard.login');
    }

    private function authorizeDashboard(Request $request): void
    {
        abort_unless(Auth::check() && $this->isAdmin($request->user()), 403, 'Administrator access is required.');
    }

    private function isAdmin(User $user): bool
    {
        return DB::table('organization_user')->where('user_id', $user->id)->where('role', 'admin')->where('active', true)->exists();
    }
}
