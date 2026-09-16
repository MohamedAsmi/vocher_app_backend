<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_complete_voucher_and_bookkeeper_can_review_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        $managerToken = $this->postJson('/api/login', [
            'email' => 'manager@example.test',
            'password' => 'ChangeMe123!',
            'deviceName' => 'phpunit',
        ])->assertOk()->assertJsonPath('role', 'outletManager')->json('token');
        $bookkeeperId = DB::table('users')->where('email', 'bookkeeper@example.test')->value('id');
        $this->assertSame('bookkeeper', DB::table('organization_user')->where(['organization_id' => 'demo-org', 'user_id' => $bookkeeperId])->value('role'));
        $manager = ['Authorization' => "Bearer {$managerToken}"];
        $this->withHeaders($manager)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('role', 'outletManager');

        $voucher = $this->withHeaders($manager)
            ->postJson('/api/organizations/demo-org/outlets/outlet_001/vouchers/today')
            ->assertOk()
            ->assertJsonPath('openingFloatMinor', 50000)
            ->json();

        $expenseId = (string) Str::uuid();
        $this->withHeaders($manager)
            ->putJson("/api/organizations/demo-org/vouchers/{$voucher['id']}", [
                'cashSalesMinor' => 100000,
                'cardSalesMinor' => 25000,
                'countedCashMinor' => 140000,
                'varianceReason' => null,
                'otherVarianceReason' => null,
                'expenses' => [[
                    'id' => $expenseId,
                    'description' => 'Delivery fuel',
                    'categoryId' => 'transport',
                    'paymentMethod' => 'cash',
                    'amountMinor' => 10000,
                    'receiptId' => null,
                ]],
            ])->assertOk();

        $operationId = (string) Str::uuid();
        $submitted = $this->withHeaders($manager)
            ->postJson("/api/organizations/demo-org/vouchers/{$voucher['id']}/submit", ['operationId' => $operationId])
            ->assertOk()
            ->assertJsonPath('status', 'pending_review')
            ->json();
        $this->assertSame(140000, $submitted['countedCashMinor']);

        $this->withHeaders($manager)
            ->postJson("/api/organizations/demo-org/vouchers/{$voucher['id']}/submit", ['operationId' => $operationId])
            ->assertOk()
            ->assertJsonPath('status', 'pending_review');
        $this->withHeaders($manager)
            ->deleteJson('/api/organizations/demo-org/receipts', [
                'receiptId' => "receipts/demo-org/{$voucher['id']}/evidence.jpg",
            ])
            ->assertConflict();
        $this->withHeaders($manager)
            ->postJson("/api/organizations/demo-org/bookkeeper/vouchers/{$voucher['id']}/approve")
            ->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $bookkeeperToken = $this->postJson('/api/login', [
            'email' => 'bookkeeper@example.test',
            'password' => 'ChangeMe123!',
            'deviceName' => 'phpunit',
        ])->assertOk()->assertJsonPath('role', 'bookkeeper')->json('token');
        $this->withHeader('Authorization', "Bearer {$bookkeeperToken}")
            ->getJson('/api/organizations/demo-org/bookkeeper/reviews?dateKey='.$voucher['dateKey'])
            ->assertOk()
            ->assertJsonPath('0.voucher.id', $voucher['id'])
            ->assertJsonPath('0.voucher.status', 'pending_review')
            ->assertJsonPath('0.voucher.expenses.0.id', $expenseId);
        $this->withHeader('Authorization', "Bearer {$bookkeeperToken}")
            ->postJson("/api/organizations/demo-org/bookkeeper/vouchers/{$voucher['id']}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'posted');
        $this->assertDatabaseHas('audit_events', [
            'voucher_id' => $voucher['id'],
            'action' => 'VOUCHER_APPROVED_AND_POSTED',
        ]);

        $this->assertDatabaseHas('journals', ['voucher_id' => $voucher['id'], 'account_name' => 'Sales Revenue']);
        $this->assertDatabaseHas('opening_floats', ['outlet_id' => 'outlet_001', 'amount_minor' => 140000]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/organizations/demo-org/outlets/outlet_001/vouchers/today')
            ->assertUnauthorized();
    }

    public function test_admin_can_manage_users_and_outlet_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'ChangeMe123!',
            'deviceName' => 'phpunit-admin',
        ])->assertOk()->assertJsonPath('role', 'admin')->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($headers)
            ->getJson('/api/organizations/demo-org/admin/users')
            ->assertOk()
            ->assertJsonCount(3, 'users');

        $created = $this->withHeaders($headers)
            ->postJson('/api/organizations/demo-org/admin/users', [
                'name' => 'Temporary Manager',
                'email' => 'temporary.manager@example.test',
                'password' => 'Temporary123!',
                'role' => 'outletManager',
                'outletIds' => ['outlet_001'],
            ])->assertCreated()->assertJsonPath('outletIds.0', 'outlet_001')->json();

        $this->withHeaders($headers)
            ->putJson("/api/organizations/demo-org/admin/users/{$created['id']}", [
                'name' => 'Temporary Bookkeeper',
                'email' => 'temporary.manager@example.test',
                'password' => 'ResetPassword123!',
                'role' => 'bookkeeper',
                'active' => true,
                'outletIds' => ['outlet_001'],
            ])->assertOk()->assertJsonPath('role', 'bookkeeper');

        $this->withHeaders($headers)
            ->deleteJson("/api/organizations/demo-org/admin/users/{$created['id']}")
            ->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', [
            'email' => 'temporary.manager@example.test',
            'password' => 'ResetPassword123!',
            'deviceName' => 'inactive-test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
