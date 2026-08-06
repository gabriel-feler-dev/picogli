<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As tabelas de conversa (Spec 006, FR-601; `PicoGli.md` §5.3).
 *
 * ## O que estas duas tabelas são, e o que NÃO são
 *
 * ⚠️ **Registro histórico, não vista derivada** (§D10). É a diferença que
 * organiza o desenho inteiro, e ela é o oposto da narrativa da fase 5:
 *
 * | | narrativa (`period_reports`) | conversa (aqui) |
 * |---|---|---|
 * | O que é | vista derivada dos achados atuais | registro do que foi dito |
 * | Regras mudam de versão | **invalida** o texto | não toca em nada |
 * | Por quê | texto sobre achados velhos é plausível e falso | reescrever apaga a auditoria do Artigo III |
 *
 * Por isso `engine_version` e `metrics_version` moram **na mensagem**: elas
 * dizem sob que versão aquela resposta foi dada, e não servem para decidir
 * regerar. Servem para explicar, dois meses depois, por que um número citado na
 * conversa difere do número da tela de hoje.
 *
 * ## Artigo IX
 *
 * `json` e nunca `jsonb`; `string` e nunca `enum` de dialeto. `role` e `outcome`
 * são strings curtas, convertidas em enum do PHP pelo model — o banco não
 * precisa saber que são enumerações, e um valor novo não vira migration.
 *
 * ⚠️ **Estas são a terceira e a quarta migrations não exercitadas em MariaDB**
 * (ver `specs/005-ia-narrativa/tasks.md` §T409.2). Nada aqui usa recurso de
 * dialeto — mas *evitado por construção* não é *verificado*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ⚠️ Nulo é o estado normal: a conversa nasce sem título e ganha um
            // depois. Exigir título no início obrigaria a inventar um antes de
            // saber do que a conversa trata.
            $table->string('title')->nullable();

            // O período em foco (§5.3). Nulo = "o período atual", resolvido na
            // hora — congelar datas aqui faria a conversa de ontem responder
            // sobre um período que já não é o corrente.
            $table->date('context_start')->nullable();
            $table->date('context_end')->nullable();

            $table->timestamps();

            // A listagem é sempre "minhas conversas, mais recente primeiro".
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();

            // `user` | `assistant` | `tool` — enum do PHP, string no banco.
            $table->string('role', 16);
            $table->text('content');

            /*
             * ⚠️ **A procedência do Artigo III mora nestas duas colunas.**
             *
             * `tool_calls` é o que o modelo pediu; `tool_results` é o que o
             * código devolveu. O rodapé "dados consultados" da tela lê daqui e
             * **não remonta** (FR-608): remontar mostraria o resultado de agora,
             * e o que torna o número auditável é ver o que foi consultado
             * naquele turno.
             */
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();

            /*
             * O que produziu esta mensagem (§D9).
             *
             * ⚠️ Sem esta coluna não há como distinguir uma resposta do modelo
             * da orientação fixa de emergência ou de um "não consegui responder"
             * — as três chegam como texto do assistente. E a diferença importa:
             * a orientação de emergência é a única que sai **sem tocar a rede**.
             */
            $table->string('outcome', 24)->nullable();

            // Qual modelo respondeu. Mesma razão de `narrative_model` na fase 5:
            // sem isso, "a resposta piorou" não tem como ser investigado.
            $table->string('model', 64)->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            /*
             * ⚠️ As versões vigentes NO TURNO (§D10). Não servem para invalidar
             * — servem para explicar, depois, por que um número citado aqui
             * difere do número da tela de hoje.
             */
            $table->string('engine_version', 32)->nullable();
            $table->string('metrics_version', 32)->nullable();

            $table->timestamps();

            // A conversa é sempre lida inteira, em ordem de criação.
            $table->index(['chat_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        // Filha primeiro: a estrangeira impede a ordem inversa.
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
