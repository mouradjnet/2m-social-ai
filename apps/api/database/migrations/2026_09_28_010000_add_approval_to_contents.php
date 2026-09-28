<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quem aprovou, e quando (ADR-13). O sistema so publica o que um humano aprovou —
 * e a aprovacao tem de ter nome, nao apenas um status.
 *
 * O historico ja sabia: toda transicao grava `content_revisions`. As pecas que JA
 * estao aprovadas herdam o autor e a hora da ultima revisao que as levou a
 * `approved`. Sem revisao registrada (peca movida por SQL), ficam sem aprovador — e
 * o PublishGate as recusa ate alguem aprovar de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users');
            $table->timestampTz('approved_at')->nullable()->after('approved_by');
        });

        DB::statement(<<<'SQL'
            UPDATE contents c
               SET approved_by = r.user_id,
                   approved_at = r.created_at
              FROM (
                    SELECT DISTINCT ON (content_id) content_id, user_id, created_at
                      FROM content_revisions
                     WHERE to_status = 'approved' AND from_status = 'review'
                  ORDER BY content_id, id DESC
                   ) r
             WHERE r.content_id = c.id
               AND c.status IN ('approved', 'scheduled', 'published', 'archived')
        SQL);
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
