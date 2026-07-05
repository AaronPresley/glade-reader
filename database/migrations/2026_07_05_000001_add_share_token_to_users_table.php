<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->after('remember_token');
        });

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['share_token' => $this->generateUniqueShareToken()]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn('share_token');
        });
    }

    private function generateUniqueShareToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (DB::table('users')->where('share_token', $token)->exists());

        return $token;
    }
};
