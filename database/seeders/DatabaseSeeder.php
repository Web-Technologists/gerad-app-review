<?php

namespace Database\Seeders;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Default Permissions
        $permissions = [
            'manage_stores' => 'Can view, create, edit and delete connected Shopify stores',
            'view_products' => 'Can browse and search products across stores',
            'edit_upi' => 'Can inline edit or bulk edit product UPI codes and statuses',
            'import_export' => 'Can run CSV bulk product imports and exports',
            'manage_users' => 'Can manage administrator users, custom roles, and permissions',
        ];

        $permissionModels = [];
        foreach ($permissions as $name => $desc) {
            $permissionModels[$name] = Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        // 2. Create Default Roles
        $roles = [
            'Super Admin' => 'Full administrative access to all features',
            'Editor' => 'Can manage products and run CSV imports/exports, cannot manage stores or users',
            'Viewer' => 'Read-only access to products directory and sync status',
        ];

        $roleModels = [];
        foreach ($roles as $name => $desc) {
            $roleModels[$name] = Role::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        // 3. Associate Permissions to Roles
        // Super Admin gets everything
        $roleModels['Super Admin']->syncPermissions(array_keys($permissions));

        // Editor gets products, upi edits, and CSV import/export
        $roleModels['Editor']->syncPermissions([
            'view_products',
            'edit_upi',
            'import_export',
        ]);

        // Viewer only gets view_products
        $roleModels['Viewer']->syncPermissions([
            'view_products',
        ]);

        // 4. Create Default Super Admin User
        $adminPassword = env('ADMIN_PASSWORD', 'admin123');
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make($adminPassword),
            ]
        );

        // Attach Super Admin role to the administrator
        $admin->syncRoles(['Super Admin']);

        $this->command->info("Seeded default roles, permissions, and administrator user: admin@example.com (Password: {$adminPassword})");
    }
}
