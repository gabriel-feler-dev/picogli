<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * O que produziu a mensagem do assistente (Spec 006, §D9).
 *
 * ⚠️ **As quatro chegam à tela como texto.** Sem registrar qual foi, uma
 * orientação de emergência é indistinguível de uma resposta do modelo — e a
 * diferença é justamente a que importa auditar: a primeira sai **sem tocar a
 * rede**.
 *
 * ## Por que existem quatro e não duas
 *
 * "Deu certo" e "deu errado" seria suficiente para a tela. Não é suficiente para
 * investigar: `Refused` é um número inventado que a guarda pegou — defeito de
 * modelo, e é sinal de que o prompt ou as ferramentas precisam de trabalho.
 * `Unavailable` é cota ou rede — não é defeito de ninguém. Somar os dois num
 * "erro" só apagaria o sinal.
 */
enum TurnOutcome: string
{
    /** O modelo respondeu e a guarda de número aprovou. */
    case Published = 'published';

    /**
     * ⚠️ Camada 4 do Artigo VI: a orientação fixa substituiu a resposta,
     * **antes** de qualquer chamada ao provedor.
     */
    case Emergency = 'emergency';

    /**
     * A resposta citou número sem procedência nos `tool_results` do turno e foi
     * descartada inteira (§D3, FR-607).
     */
    case Refused = 'refused';

    /** Nenhum modelo da cadeia atendeu, ou o teto do laço estourou (§D5). */
    case Unavailable = 'unavailable';

    /** A rede foi tocada para produzir esta mensagem? */
    public function reachedProvider(): bool
    {
        return $this !== self::Emergency;
    }

    /** Vale investigar? `Refused` é defeito nosso; `Unavailable`, do dia. */
    public function deservesInvestigation(): bool
    {
        return $this === self::Refused;
    }
}
