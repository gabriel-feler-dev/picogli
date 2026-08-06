<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\Persistence\ChatMessageWriter;
use App\Domain\Ai\Chat\Persistence\PeriodMetricsTool;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ChatTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SensorReading;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A tela e o endpoint do chat (Spec 006, FR-609, FR-610, §D11).
 *
 * ⚠️ **O `ChatScope` nasce aqui, da sessão** (§D2). É o único lugar do sistema
 * que decide de quem são os dados — as ferramentas recebem o escopo pronto, e o
 * schema delas sequer aceita `user_id`.
 *
 * ⚠️ **Sem streaming, e de propósito** (§D11). A resposta é persistida e a
 * página recarrega com ela. O SSE do §9.5 é enriquecimento previsto para depois:
 * ADR-5b avisa que hospedagem compartilhada tem timeout curto e buffer que não
 * se controla do código, e um chat que **depende** de SSE é um chat que pode não
 * funcionar no destino — descoberto no deploy.
 */
final class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $conversas = ChatConversation::forUser($this->userId($request))
            ->limit(30)
            ->get(['id', 'title', 'updated_at']);

        return Inertia::render('Chat', [
            'conversations' => $conversas,
            'conversation' => null,
            'messages' => [],
            'suggestions' => $this->suggestions(),
            'has_data' => $this->hasData($this->userId($request)),
        ]);
    }

    public function show(Request $request, ChatConversation $conversation): Response
    {
        $this->authorise($request, $conversation);

        return Inertia::render('Chat', [
            'conversations' => ChatConversation::forUser($this->userId($request))
                ->limit(30)
                ->get(['id', 'title', 'updated_at']),
            'conversation' => $conversation->only(['id', 'title']),
            'messages' => $this->messagesOf($conversation),
            'suggestions' => $this->suggestions(),
            'has_data' => $this->hasData($this->userId($request)),
        ]);
    }

    /** Abre uma conversa vazia — o título vem da primeira pergunta. */
    public function store(Request $request): RedirectResponse
    {
        $conversa = ChatConversation::create(['user_id' => $this->userId($request)]);

        return redirect()->route('chat.show', $conversa);
    }

    /**
     * Um turno completo.
     *
     * ⚠️ **Nada aqui trata exceção de IA**, porque o orquestrador não lança: ele
     * devolve um `ChatTurn` com o desfecho (Artigo I).
     */
    public function message(Request $request, ChatConversation $conversation, ChatOrchestrator $orchestrator, ChatMessageWriter $writer): RedirectResponse
    {
        $this->authorise($request, $conversation);

        $dados = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $mensagem = trim($dados['message']);
        $userId = $this->userId($request);

        // ⚠️ A pergunta é gravada ANTES de qualquer chamada ao provedor: se ele
        // cair no meio, ela não some da conversa.
        $writer->recordQuestion($conversation, $mensagem);
        $writer->titleFrom($conversation, $mensagem);

        $turno = $orchestrator->handle(
            $mensagem,
            new ChatScope($userId, (int) config('chat.max_span_days')),
            $this->historyOf($conversation),
            $this->contextFor($userId),
        );

        $this->log($turno, $conversation);

        $writer->recordAnswer($conversation, $turno, $this->fallbackFor($turno));

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * ⚠️ O contexto pré-carregado do §9.3 — período e métricas globais, sempre
     * presentes, para que a pergunta simples não custe uma volta do laço.
     *
     * @return array<string, mixed>
     */
    private function contextFor(int $userId): array
    {
        $ultima = SensorReading::where('user_id', $userId)->max('local_date');

        if ($ultima === null) {
            return [];
        }

        $to = substr((string) $ultima, 0, 10);
        $from = (new DateTimeImmutable($to))->modify('-13 days')->format('Y-m-d');

        return app(PeriodMetricsTool::class)->metrics(
            new ChatScope($userId, (int) config('chat.max_span_days')),
            $from,
            $to,
        ) + ['period_start' => $from, 'period_end' => $to];
    }

    /** @return list<array{role: string, content: string}> */
    private function historyOf(ChatConversation $conversation): array
    {
        return $conversation->visibleMessages()
            ->get(['role', 'content'])
            ->map(fn (ChatMessage $m): array => [
                'role' => $m->role->value,
                'content' => (string) $m->content,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function messagesOf(ChatConversation $conversation): array
    {
        return $conversation->visibleMessages()
            ->get()
            ->map(fn (ChatMessage $m): array => [
                'id' => $m->id,
                'role' => $m->role->value,
                'content' => (string) $m->content,
                // ⚠️ FR-608: lido do que foi GRAVADO, nunca remontado. O que
                // torna o número auditável é ver o que foi consultado naquele
                // turno — não o resultado de agora.
                'consulted' => $m->consultedData(),
                'model' => $m->model,
            ])
            ->all();
    }

    /**
     * ⚠️ §D9 — no chat, silêncio é tela travada.
     *
     * E ⚠️ NFR-502 continua valendo: nada de cota, chave, modelo ou cooldown
     * chega à tela. As duas frases dizem a mesma coisa ao usuário; a diferença
     * entre elas está no `outcome` gravado, que é para nós.
     */
    private function fallbackFor(ChatTurn $turn): string
    {
        return __('chat.unavailable');
    }

    /**
     * A borda que registra — o domínio é puro e não chama `Log::`.
     */
    private function log(ChatTurn $turn, ChatConversation $conversation): void
    {
        $contexto = [
            'conversation' => $conversation->id,
            'outcome' => $turn->outcome->value,
            'iterations' => $turn->iterations,
            'tools' => count($turn->toolCalls),
        ];

        // ⚠️ `Refused` é defeito NOSSO — o modelo citou número sem procedência,
        // e isso é sinal de que o prompt ou as ferramentas precisam de trabalho.
        // `Unavailable` é cota ou rede, e não é defeito de ninguém.
        if ($turn->outcome->deservesInvestigation()) {
            Log::warning('chat: resposta recusada pela guarda de número', $contexto + [
                'orphans' => $turn->orphanNumbers,
            ]);

            return;
        }

        Log::info('chat: turno concluído', $contexto);
    }

    private function authorise(Request $request, ChatConversation $conversation): void
    {
        abort_unless($conversation->user_id === $this->userId($request), 404);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->id;
    }

    private function hasData(int $userId): bool
    {
        return SensorReading::where('user_id', $userId)->exists();
    }

    /** @return list<string> as sugestões da tela vazia (§10.3) */
    private function suggestions(): array
    {
        return array_values((array) __('chat.suggestions'));
    }
}
