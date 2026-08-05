<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;

/**
 * Uma regra determinística de detecção de padrão (Artigo II).
 *
 * ⚠️ **Função pura.** Recebe um `PatternDataset` e devolve achados. Não consulta
 * banco, não lê configuração global, não olha o relógio, não formata texto de
 * interface.
 *
 * Cada uma dessas proibições existe por um motivo diferente:
 *
 * | Proibido | Por quê |
 * |---|---|
 * | banco | dez regras × consultas sobre a mesma série = N+1 invisível; e o teste da regra passaria a exigir fixture |
 * | `config()` | tira a regra do PHP puro e obriga o container no teste de unidade |
 * | `now()` | regra que consulta o relógio não é determinística, e determinismo é o Artigo II |
 * | texto de interface | Artigo IV só é verificável se o texto viver em `lang/` |
 *
 * Imposto por teste de busca sobre o diretório (NFR-401), com autoteste — não
 * por disciplina.
 *
 * **Zero achado é resultado legítimo**, não erro. Devolver `[]` é o caminho
 * normal de uma regra que não encontrou seu padrão.
 */
interface Rule
{
    public function id(): RuleId;

    /**
     * @return list<Finding> zero ou mais achados; nunca `null`
     */
    public function evaluate(PatternDataset $dataset): array;
}
