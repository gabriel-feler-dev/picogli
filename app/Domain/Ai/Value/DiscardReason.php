<?php

declare(strict_types=1);

namespace App\Domain\Ai\Value;

/**
 * Por que uma narrativa não foi publicada.
 *
 * ⚠️ **Nenhuma destas razões é erro de tela.** Em todas, a tela cai para o
 * `fallbackProse` dos achados — que já é publicável desde a fase 4 — e o usuário
 * vê exatamente o que veria ontem (Artigo I, NFR-502).
 *
 * O enum existe para o **log** ser investigável. "Não houve narrativa" é uma
 * informação inútil; "a narrativa foi descartada porque citou 187 sem
 * procedência" é acionável.
 */
enum DiscardReason: string
{
    /** Todos os modelos da cadeia estavam de castigo, ou a chave é inválida. */
    case NoModelAvailable = 'no_model_available';

    /** O provedor respondeu, mas com texto vazio. */
    case EmptyResponse = 'empty_response';

    /** ⚠️ §D5 — número na prosa sem procedência na evidência. */
    case OrphanNumbers = 'orphan_numbers';

    /**
     * Resposta muito além do teto de palavras.
     *
     * Não é policiamento de estilo: é proteção contra saída em fuga, que quebraria
     * o layout da tela e provavelmente indica que o modelo ignorou o prompt.
     */
    case TooLong = 'too_long';

    /** Não havia achado nenhum para narrar (§D10 — e isso é boa notícia). */
    case NothingToNarrate = 'nothing_to_narrate';

    public function isFailure(): bool
    {
        // ⚠️ "Nada a narrar" não é falha: é um período sem padrão detectado, que
        // a fase 4 já trata como boa notícia. Logar como erro treinaria quem lê
        // o log a ignorá-lo.
        return $this !== self::NothingToNarrate;
    }
}
