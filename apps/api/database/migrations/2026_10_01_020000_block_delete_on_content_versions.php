<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CP-04E: a versao tambem nao se apaga. O CP-04C so recusava UPDATE; DELETE ficava
 * possivel pelo cascade da peca (e do projeto/workspace) ou por SQL direto, e um
 * TRUNCATE limpava o historico inteiro.
 *
 * Reaproveita a funcao content_versions_imutavel(). O cascade tambem dispara o trigger
 * de linha: apagar peca, projeto ou workspace que tenha versoes passa a ser recusado
 * pelo banco. Nenhuma rota faz isso hoje; quando houver exclusao de conta, ela tera de
 * ser um caminho deliberado.
 *
 * O restore (`pg_restore --clean`) usa DROP TABLE, que nao dispara estes triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER content_versions_sem_delete BEFORE DELETE ON content_versions
                FOR EACH ROW EXECUTE FUNCTION content_versions_imutavel();

            CREATE TRIGGER content_versions_sem_truncate BEFORE TRUNCATE ON content_versions
                FOR EACH STATEMENT EXECUTE FUNCTION content_versions_imutavel();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS content_versions_sem_delete ON content_versions;
            DROP TRIGGER IF EXISTS content_versions_sem_truncate ON content_versions;
            SQL);
    }
};
