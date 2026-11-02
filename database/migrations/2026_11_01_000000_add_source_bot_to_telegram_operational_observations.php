<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'telegram_operational_observations_revision_unique';

    private const BOT_AWARE_UNIQUE = 'telegram_operational_observations_bot_revision_unique';

    public function up(): void
    {
        Schema::connection('analytics')->table('telegram_operational_observations', function (Blueprint $table): void {
            $table->string('source_bot', 20)->default('legacy');
            $table->dropUnique(self::OLD_UNIQUE);
            $table->unique(
                ['telegram_message_id', 'source_bot', 'source_revision_hash', 'evaluation_kind'],
                self::BOT_AWARE_UNIQUE,
            );
        });
    }

    public function down(): void
    {
        $hasDuplicateIdentityAcrossBots = DB::connection('analytics')
            ->table('telegram_operational_observations')
            ->select('telegram_message_id', 'source_revision_hash', 'evaluation_kind')
            ->groupBy('telegram_message_id', 'source_revision_hash', 'evaluation_kind')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateIdentityAcrossBots) {
            throw new RuntimeException(
                'Cannot remove source_bot while observations with distinct bot identities share a revision.',
            );
        }

        Schema::connection('analytics')->table('telegram_operational_observations', function (Blueprint $table): void {
            $table->dropUnique(self::BOT_AWARE_UNIQUE);
            $table->dropColumn('source_bot');
        });

        Schema::connection('analytics')->table('telegram_operational_observations', function (Blueprint $table): void {
            $table->unique(
                ['telegram_message_id', 'source_revision_hash', 'evaluation_kind'],
                self::OLD_UNIQUE,
            );
        });
    }
};
