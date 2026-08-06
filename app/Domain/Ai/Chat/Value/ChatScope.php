<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

use InvalidArgumentException;

/**
 * De quem são os dados, e até onde uma consulta pode ir (Spec 006, §D2).
 *
 * ## ⚠️ O ponto inteiro desta classe
 *
 * **`user_id` nunca vem do modelo.** Ele é construído a partir da sessão
 * autenticada e viaja ao lado dos argumentos que o modelo escolheu, nunca
 * dentro deles.
 *
 * ```php
 * $tool->run($argumentosDoModelo, $scope);
 * //         ↑ entrada não confiável   ↑ vem da sessão
 * ```
 *
 * *Por quê um tipo e não um `int`:* um `int` solto na assinatura pode receber
 * qualquer inteiro — inclusive um que veio de `$args['user_id']`, num descuido
 * de cinco segundos. Um `ChatScope` só existe se alguém o construir, e quem o
 * constrói é o controller, a partir de `auth()`.
 *
 * ⚠️ **Argumento de ferramenta é a ÚNICA entrada deste sistema em que o texto
 * do usuário influencia uma query.** Chamar isso de entrada não confiável e
 * tratar como tal é a diferença entre uma ferramenta e uma injeção.
 *
 * ## O span máximo mora aqui, e não em cada ferramenta
 *
 * Dez ferramentas com o próprio limite são dez chances de esquecer um. Uma
 * pergunta inocente — "como foi meu último ano?" — vira uma varredura de 105 mil
 * leituras, e o limite existe para que a resposta seja "esse período é longo
 * demais", não um timeout.
 */
final readonly class ChatScope
{
    public function __construct(
        public int $userId,
        public int $maxSpanDays,
    ) {
        if ($this->userId <= 0) {
            throw new InvalidArgumentException(
                "ChatScope com user_id inválido ({$this->userId}). "
                .'Ele vem da sessão autenticada, nunca de argumento de ferramenta.'
            );
        }

        if ($this->maxSpanDays <= 0) {
            throw new InvalidArgumentException(
                "Span máximo inválido ({$this->maxSpanDays} dias). "
                .'Sem teto, uma pergunta sobre "o último ano" varre a base inteira.'
            );
        }
    }

    /** O período pedido cabe no teto? */
    public function allowsSpan(int $days): bool
    {
        return $days >= 1 && $days <= $this->maxSpanDays;
    }
}
