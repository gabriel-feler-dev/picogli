<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas de controle da importação.
 *
 * Portabilidade (Artigo IX): sem recurso exclusivo de dialeto. Alvo SQLite em
 * desenvolvimento e MySQL em produção — a Hostinger compartilhada não oferece
 * PostgreSQL, então nada de colunas geradas, JSONB ou tipos de array.
 *
 * `$table->json()` é aceitável: vira `json` no MySQL e `TEXT` no SQLite, e o
 * Laravel serializa igual nos dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('original_filename');

            // sha256 do arquivo. Reenviar o MESMO arquivo é no-op (FR-006):
            // a chave única aborta antes de processar 4 mil linhas de novo.
            $table->string('file_hash', 64);

            $table->string('device_model')->nullable();

            // ⚠️ PII (Artigo VII). Gravado para distinguir dispositivos do mesmo
            // usuário, mas NUNCA sai para provedor de IA — quem controla essa
            // fronteira é o PayloadSanitizer, não o schema.
            $table->string('device_serial')->nullable();

            $table->string('firmware_version')->nullable();
            $table->string('cgm_model')->nullable();

            // ⚠️ §A5 — o CSV não carrega fuso. Este valor é informado no upload
            // e é o que permite derivar recorded_at_utc. Sem ele, todo insight
            // de horário sai deslocado com números plausíveis.
            $table->string('timezone', 64);

            // §A7 — nunca assumir. Exports de outras regiões vêm em mmol/L.
            $table->string('glucose_unit', 10);

            // Nullable: um export atípico sem Start/End Date ainda é
            // importável — os eventos carregam os próprios instantes. A
            // ausência vira aviso em parse_warnings, não crash.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // FR-010 — transparência: o pior cenário é import silenciosamente
            // parcial. O usuário precisa ver que a contagem bate.
            $table->json('block_row_counts')->nullable();
            $table->json('parse_warnings')->nullable();

            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['user_id', 'file_hash']);
            $table->index(['user_id', 'period_start']);
        });

        /**
         * Perfil de razão de carboidrato e sensibilidade, reconstruído das
         * colunas `BWZ *` (FR-008). O relatório "Definições do dispositivo" não
         * existe no CSV, mas cada bolus registra a configuração vigente.
         */
        Schema::create('device_settings_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained();

            $table->date('valid_from');

            $table->json('carb_ratio_profile');          // hora → g/U
            $table->json('isf_values');                  // lista de mg/dL/U
            $table->json('basal_profile')->nullable();   // hora → U/h

            // Hash do conteúdo. Reimportar não cria snapshot duplicado; um novo
            // só nasce quando a configuração muda de fato.
            $table->string('fingerprint', 64);

            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_settings_snapshots');
        Schema::dropIfExists('imports');
    }
};
