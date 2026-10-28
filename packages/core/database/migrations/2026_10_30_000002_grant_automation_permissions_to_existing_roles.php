<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives existing default roles the automation permissions, matching what new workspaces get.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Admin' => ['automations.view', 'automations.manage'],
        'Editor' => ['automations.view', 'automations.manage'],
        'Viewer' => ['automations.view'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleName => $grants) {
            DB::table('roles')->where('name', $roleName)->where('is_owner', false)->orderBy('id')
                ->each(function (object $role) use ($grants): void {
                    $permissions = json_decode($role->permissions, true) ?: [];

                    DB::table('roles')->where('id', $role->id)->update([
                        'permissions' => json_encode(array_values(array_unique([...$permissions, ...$grants]))),
                    ]);
                });
        }
    }

    public function down(): void
    {
        //
    }
};
