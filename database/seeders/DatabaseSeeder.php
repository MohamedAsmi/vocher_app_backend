<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $admin = User::updateOrCreate(['email' => env('SEED_ADMIN_EMAIL', 'admin@example.test')], ['name' => 'Administrator', 'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'ChangeMe123!'))]);
        $manager = User::updateOrCreate(['email' => env('SEED_MANAGER_EMAIL', 'manager@example.test')], ['name' => 'Outlet Manager', 'password' => Hash::make(env('SEED_MANAGER_PASSWORD', 'ChangeMe123!'))]);
        $bookkeeper = User::updateOrCreate(['email' => env('SEED_BOOKKEEPER_EMAIL', 'bookkeeper@example.test')], ['name' => 'Bookkeeper', 'password' => Hash::make(env('SEED_BOOKKEEPER_PASSWORD', 'ChangeMe123!'))]);
        DB::table('organizations')->updateOrInsert(['id' => 'demo-org'], ['name' => 'Daily Cash Voucher', 'timezone' => 'Asia/Colombo', 'currency_code' => 'LKR', 'updated_at' => now(), 'created_at' => now()]);
        DB::table('outlets')->updateOrInsert(['id' => 'outlet_001'], ['organization_id' => 'demo-org', 'code' => '001', 'name' => 'Main Outlet', 'manager_name' => $manager->name, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
        foreach ([[$admin->id, 'admin'], [$manager->id, 'outletManager'], [$bookkeeper->id, 'bookkeeper']] as [$id, $role]) {
            DB::table('organization_user')->updateOrInsert(['organization_id' => 'demo-org', 'user_id' => $id], ['role' => $role, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([$manager->id, $bookkeeper->id] as $id) {
            DB::table('outlet_user')->updateOrInsert(['outlet_id' => 'outlet_001', 'user_id' => $id], ['active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([['raw_materials', 'Raw Materials', '5100'], ['packaging', 'Packaging', '5200'], ['utilities', 'Gas/Electricity/Water', '5300'], ['wages', 'Wages', '5400'], ['transport', 'Transport', '5500'], ['repairs', 'Repairs', '5600']] as $i => [$id, $name, $code]) {
            DB::table('expense_categories')->updateOrInsert(['id' => $id], ['organization_id' => 'demo-org', 'name' => $name, 'account_code' => $code, 'account_name' => $name, 'sort_order' => $i, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('opening_floats')->updateOrInsert(['outlet_id' => 'outlet_001'], ['amount_minor' => 50000, 'updated_at' => now(), 'created_at' => now()]);
    }
}
