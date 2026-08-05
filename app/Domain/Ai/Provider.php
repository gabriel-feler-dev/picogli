<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Value\AiPayload;
use App\Domain\Ai\Value\AiResult;

/**
 * Um provedor de modelo de linguagem (ADR-6, FR-502).
 *
 * ⚠️ **Recebe o prompt e o payload SEPARADOS**, e não uma string já montada.
 *
 * *Por quê:* o `AiPayload` é o objeto que o teste anti-vazamento inspeciona. Se o
 * chamador entregasse uma string pronta, a verificação do Artigo VII passaria a
 * depender de alguém ter montado a string corretamente — e não haveria o que
 * varrer. Separados, o payload continua sendo um objeto tipado até a última
 * fronteira.
 *
 * ⚠️ **A implementação real vive em `app/Infrastructure/Ai/`**, não aqui. Esta
 * interface é domínio puro; quem fala HTTP é borda, como `Persistence/`.
 *
 * ## Contrato de falha
 *
 * Lança `ProviderUnavailable` com a razão **classificada**. Classificar é
 * obrigação da implementação, porque só ela sabe traduzir o corpo da resposta do
 * provedor em `ProviderFailure` — a API devolve 429 tanto para limite por minuto
 * quanto para cota diária, e as duas coisas têm cooldown de escalas diferentes.
 */
interface Provider
{
    /**
     * @throws ProviderUnavailable com a razão classificada
     */
    public function generate(string $model, string $prompt, AiPayload $payload): AiResult;

    /** Identificador curto para log e para `narrative_model`. */
    public function name(): string;
}
