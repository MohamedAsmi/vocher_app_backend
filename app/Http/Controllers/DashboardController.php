<?php

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            ->where('success', false)
            ->when($request->filled('path'), fn ($query) => $query->where('path', 'like', '%'.$request->string('path')->value().'%'))
            ->latest()
            ->paginate(50)
            ->withQueryString();
        $successfulLogs = ApiRequestLog::query()
            ->with('user:id,name,email')
            ->where('success', true)
            ->when($request->filled('path'), fn ($query) => $query->where('path', 'like', '%'.$request->string('path')->value().'%'))
            ->latest()
            ->paginate(50, ['*'], 'success_page')
            ->withQueryString();

        return view('dashboard.index', [
            'logs' => $logs,
            'successfulLogs' => $successfulLogs,
            'users' => User::query()->select('id', 'name', 'email', 'created_at')->orderBy('name')->get(),
            'stats' => [
                'total' => ApiRequestLog::count(),
                'success' => ApiRequestLog::where('success', true)->count(),
                'failed' => ApiRequestLog::where('success', false)->count(),
            ],
        ]);
    }

    public function vouchers(Request $request): View
    {
        $this->authorizeDashboard($request);
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'manager' => ['nullable', 'string', 'max:255'],
        ]);
        $date = $filters['date'] ?? null;

        $vouchers = DB::table('vouchers')
            ->join('organizations', 'organizations.id', '=', 'vouchers.organization_id')
            ->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'vouchers.created_by')
            ->leftJoin('users as posters', 'posters.id', '=', 'vouchers.posted_by')
            ->whereIn('vouchers.status', ['open', 'pending_review', 'posted', 'variance'])
            ->when($date, fn ($query, $date) => $query->where('vouchers.date_key', Carbon::parse($date)->format('Ymd')))
            ->when($filters['manager'] ?? null, fn ($query, $manager) => $query->where('outlets.manager_name', $manager))
            ->orderByDesc('vouchers.posted_at')
            ->select([
                'vouchers.*',
                'organizations.name as organization_name',
                'outlets.name as outlet_name',
                'outlets.manager_name',
                'creators.name as creator_name',
                'creators.email as creator_email',
                'posters.name as poster_name',
                'posters.email as poster_email',
            ])
            ->get()
            ->map(fn ($voucher) => $this->dashboardVoucher($voucher))
            ->all();

        $managers = DB::table('outlets')
            ->whereNotNull('manager_name')
            ->distinct()
            ->orderBy('manager_name')
            ->pluck('manager_name');

        return view('dashboard.vouchers', compact('vouchers', 'managers', 'date'));
    }

    public function voucher(Request $request, string $voucher): View
    {
        $this->authorizeDashboard($request);
        $row = DB::table('vouchers')
            ->join('organizations', 'organizations.id', '=', 'vouchers.organization_id')
            ->join('outlets', 'outlets.id', '=', 'vouchers.outlet_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'vouchers.created_by')
            ->leftJoin('users as posters', 'posters.id', '=', 'vouchers.posted_by')
            ->where('vouchers.id', $voucher)
            ->select([
                'vouchers.*',
                'organizations.name as organization_name',
                'outlets.name as outlet_name',
                'outlets.manager_name',
                'creators.name as creator_name',
                'creators.email as creator_email',
                'posters.name as poster_name',
                'posters.email as poster_email',
            ])
            ->first();
        abort_unless($row, 404);

        return view('dashboard.voucher', ['item' => $this->dashboardVoucher($row)]);
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

    public function verifyPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeDashboard($request);
        $password = $request->validate(['password' => ['required', 'string']])['password'];
        $matches = Hash::check($password, $user->password);

        return back()->with($matches ? 'status' : 'error', $matches
            ? "The password is correct for {$user->email}."
            : "The password is incorrect for {$user->email}.");
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

    private function dashboardVoucher(object $voucher): array
    {
        $submission = DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->where('audit_events.voucher_id', $voucher->id)
            ->where('audit_events.action', 'VOUCHER_SUBMITTED_FOR_REVIEW')
            ->latest('audit_events.created_at')
            ->select([
                'audit_events.action',
                'audit_events.payload',
                'audit_events.created_at',
                'users.name as manager_name',
                'users.email as manager_email',
            ])
            ->first();

        $approval = DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->where('audit_events.voucher_id', $voucher->id)
            ->where('audit_events.action', 'VOUCHER_APPROVED_AND_POSTED')
            ->latest('audit_events.created_at')
            ->select([
                'audit_events.action',
                'audit_events.payload',
                'audit_events.created_at',
                'users.name as reviewer_name',
                'users.email as reviewer_email',
            ])
            ->first();

        if (! $approval && in_array($voucher->status, ['posted', 'variance'], true) && $voucher->posted_by) {
            $approval = (object) [
                'action' => 'VOUCHER_APPROVED_AND_POSTED',
                'payload' => null,
                'created_at' => $voucher->posted_at,
                'reviewer_name' => $voucher->poster_name,
                'reviewer_email' => $voucher->poster_email,
            ];
        }

        $expenses = DB::table('expenses')->where('voucher_id', $voucher->id)->orderBy('created_at')->get();
        $sales = $voucher->total_sales_minor === null
            ? (int) $voucher->cash_sales_minor + (int) $voucher->card_sales_minor
            : (int) $voucher->total_sales_minor;
        $expenseTotal = $voucher->total_expenses_minor === null
            ? (int) $expenses->sum('amount_minor')
            : (int) $voucher->total_expenses_minor;

        return [
            'voucher' => $voucher,
            'submission' => $submission,
            'approval' => $approval,
            'displaySalesMinor' => $sales,
            'displayExpensesMinor' => $expenseTotal,
            'displayVarianceMinor' => $voucher->variance_minor === null ? null : (int) $voucher->variance_minor,
            'expenses' => $expenses,
            'journals' => DB::table('journals')->where('voucher_id', $voucher->id)->orderBy('type')->orderBy('line_number')->get(),
            'auditEvents' => DB::table('audit_events')->where('voucher_id', $voucher->id)->orderBy('created_at')->get(),
        ];
    }
}
