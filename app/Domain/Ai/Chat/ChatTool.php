<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;

/**
 * Uma consulta que o modelo pode pedir (Spec 006, FR-602, ADR-3).
 *
 * ⚠️ **O modelo não recebe os dados. Recebe isto.** É a diferença entre "confie
 * que ele não vai inventar" e "ele não tem o que inventar" — o Artigo III virando
 * arquitetura em vez de instrução de prompt.
 *
 * ## Duas responsabilidades, e a segunda tem uma regra
 *
 * ```php
 * $tool->run($argumentosDoModelo, $scope);
 * //          ↑ não confiável       ↑ da sessão
 * ```
 *
 * ⚠️ **`$args` NUNCA decide de quem são os dados.** Toda query é escopada por
 * `$scope->userId`, e o schema declarado em `describe()` sequer aceita o campo —
 * o `ToolDescriptor` recusa na construção (§D2).
 *
 * ## Onde as implementações moram
 *
 * Em `Chat/Persistence/`, e não em `Chat/Tools/` como sugere o `PicoGli.md`
 * §9.3. O caminho do §9.3 é esboço de produto; a regra de pureza (NFR-401) é a
 * que vale — `app/Domain/**` não toca Eloquent fora de `Persistence/`. Esta
 * interface, sendo contrato, fica no domínio puro.
 */
interface ChatTool
{
    /** Nome, descrição para o modelo, schema de argumentos e chaves emitidas. */
    public function describe(): ToolDescriptor;

    /**
     * Executa a consulta.
     *
     * ⚠️ **Não valida argumento** — quando chega aqui, o `ToolRegistry` já
     * validou contra o schema declarado. Revalidar espalharia a regra por dez
     * classes, e onde a regra está em dez lugares ela está em nenhum.
     *
     * @param  array<string, mixed>  $args  já validados
     */
    public function run(array $args, ChatScope $scope): ToolResult;
}
