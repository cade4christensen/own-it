<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Game;
use App\Models\Player;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        return $this->snapshot($request, Game::current());
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nick' => ['required', 'string', 'max:20'],
            'avatar' => ['nullable', Rule::in(array_keys(config('game.avatars')))],
        ]);
        $token = $this->token($request);
        abort_if($token === null, 422, 'Reload the page and try again.');

        $game = Game::current();
        Player::updateOrCreate(
            ['game_id' => $game->id, 'token' => $token],
            ['nick' => $this->uniqueNick($game, $token, trim($data['nick'])), 'avatar' => $data['avatar'] ?? null],
        );

        return $this->snapshot($request, $game);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:160']]);
        $game = Game::current();
        $player = $this->player($request, $game);
        abort_if($player === null, 422, 'Join the game first.');
        abort_if($game->phase !== 'write', 422, 'Answers are closed for this round.');

        Answer::updateOrCreate(
            ['game_id' => $game->id, 'round' => $game->round, 'player_id' => $player->id],
            ['text' => trim($data['text'])],
        );

        return $this->snapshot($request, $game);
    }

    public function vote(Request $request): JsonResponse
    {
        $data = $request->validate(['answer_id' => ['required', 'integer']]);
        $game = Game::current();
        $player = $this->player($request, $game);
        abort_if($player === null, 422, 'Join the game first.');
        abort_if($game->phase !== 'vote', 422, 'Voting is closed for this round.');

        $answer = $game->answers()->where('round', $game->round)->find($data['answer_id']);
        abort_if($answer === null, 422, 'That answer is no longer there.');
        abort_if($answer->player_id === $player->id, 422, "You can't vote for your own answer.");

        Vote::updateOrCreate(
            ['game_id' => $game->id, 'round' => $game->round, 'player_id' => $player->id],
            ['answer_id' => $answer->id],
        );

        return $this->snapshot($request, $game);
    }

    /**
     * Move the game to its next phase. Only the host can do this.
     */
    public function advance(Request $request): JsonResponse
    {
        $this->authorizeHost($request);
        $data = $request->validate([
            'action' => ['required', 'in:start,vote,reveal,next,end,new'],
            'phase' => ['nullable', 'string'],
            'round' => ['nullable', 'integer'],
        ]);
        $game = Game::current();
        $now = (int) round(microtime(true) * 1000);

        // A double click or a slow connection can send the same button twice. Only act
        // when the game is still where the host's screen showed it, so a round is never skipped.
        $allowedFrom = [
            'start' => ['lobby'],
            'vote' => ['write'],
            'reveal' => ['vote'],
            'next' => ['reveal'],
            'end' => ['write', 'vote', 'reveal'],
            'new' => ['lobby', 'write', 'vote', 'reveal', 'end'],
        ][$data['action']];
        $stale = (isset($data['phase']) && $data['phase'] !== $game->phase)
            || (isset($data['round']) && (int) $data['round'] !== $game->round);
        if ($stale || ! in_array($game->phase, $allowedFrom)) {
            return $this->snapshot($request, $game);
        }

        switch ($data['action']) {
            case 'start':
                abort_if(count($game->prompts) === 0, 422, 'Add at least one prompt first.');
                $game->update(['phase' => 'write', 'round' => 0, 'ends_at' => $now + config('game.write_seconds') * 1000]);
                break;
            case 'vote':
                $game->update(['phase' => 'vote', 'ends_at' => $now + config('game.vote_seconds') * 1000]);
                break;
            case 'reveal':
                $game->update(['phase' => 'reveal', 'ends_at' => 0]);
                break;
            case 'next':
                if ($game->round + 1 >= count($game->prompts)) {
                    $game->update(['phase' => 'end', 'ends_at' => 0]);
                } else {
                    $game->update(['phase' => 'write', 'round' => $game->round + 1, 'ends_at' => $now + config('game.write_seconds') * 1000]);
                }
                break;
            case 'end':
                $game->update(['phase' => 'end', 'ends_at' => 0]);
                break;
            case 'new':
                $game = Game::create(['prompts' => $game->prompts]);
                break;
        }

        return $this->snapshot($request, $game);
    }

    public function prompts(Request $request): JsonResponse
    {
        $this->authorizeHost($request);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:50'],
            'items.*.setup' => ['nullable', 'string', 'max:200'],
            'items.*.line' => ['nullable', 'string', 'max:200'],
            'items.*.ask' => ['nullable', 'string', 'max:120'],
            'items.*.value' => ['nullable', 'string', 'max:60'],
            'items.*.point' => ['nullable', 'string', 'max:300'],
        ]);
        $game = Game::current();
        abort_if($game->phase !== 'lobby', 422, 'Prompts can only be changed in the lobby.');

        $prompts = array_map(fn (array $item) => [
            'setup' => trim($item['setup'] ?? ''),
            'line' => trim($item['line'] ?? ''),
            'ask' => trim($item['ask'] ?? ''),
            'value' => trim($item['value'] ?? ''),
            'point' => trim($item['point'] ?? ''),
        ], $data['items']);

        $game->update(['prompts' => array_values(array_filter(
            $prompts,
            fn (array $prompt) => $prompt['setup'] !== '' || $prompt['line'] !== '',
        ))]);

        return $this->snapshot($request, $game);
    }

    public function remove(Request $request): JsonResponse
    {
        $this->authorizeHost($request);
        $data = $request->validate(['answer_id' => ['required', 'integer']]);
        $game = Game::current();
        $game->answers()->whereKey($data['answer_id'])->delete();

        return $this->snapshot($request, $game);
    }

    /**
     * Everything one browser needs to draw the current screen. Vote counts stay
     * hidden until the reveal, and only a round's winning answer names its author.
     */
    private function snapshot(Request $request, Game $game): JsonResponse
    {
        $isHost = $this->isHost($request);
        $player = $this->player($request, $game);
        $round = $game->round;
        $prompts = $game->prompts;

        $out = [
            'now' => (int) round(microtime(true) * 1000),
            'isHost' => $isHost,
            'game' => [
                'id' => $game->id,
                'phase' => $game->phase,
                'round' => $round,
                'endsAt' => $game->ends_at,
                'total' => count($prompts),
            ],
            'prompt' => $prompts[$round] ?? null,
            'players' => $game->players()->count(),
            'answerCount' => $game->answers()->where('round', $round)->count(),
            'voteCount' => $game->votes()->where('round', $round)->count(),
            'me' => null,
        ];

        if ($player) {
            $mine = $game->answers()->where('round', $round)->where('player_id', $player->id)->first();
            $out['me'] = [
                'nick' => $player->nick,
                'avatar' => $player->avatar,
                'answer' => $mine?->text,
                'answerId' => $mine?->id,
                'vote' => $game->votes()->where('round', $round)->where('player_id', $player->id)->value('answer_id'),
            ];
        }

        if ($game->phase === 'lobby') {
            $out['roster'] = $game->players()->orderBy('id')->get(['nick', 'avatar']);
            if ($isHost) {
                $out['prompts'] = $prompts;
            }
        }

        if (in_array($game->phase, ['vote', 'reveal'])) {
            $reveal = $game->phase === 'reveal';
            $answers = $game->answers()->where('round', $round)->get(['id', 'text', 'player_id']);
            $counts = $reveal
                ? $game->votes()->where('round', $round)->selectRaw('answer_id, count(*) as n')->groupBy('answer_id')->pluck('n', 'answer_id')
                : collect();
            $top = (int) $counts->max();
            $authors = $top > 0 ? $game->players()->get()->keyBy('id') : collect();

            $out['answers'] = $answers
                ->map(fn (Answer $a) => [
                    'id' => $a->id,
                    'text' => $a->text,
                    'votes' => (int) ($counts[$a->id] ?? 0),
                    // Only the round's winner is named. Every other answer stays anonymous.
                    'author' => $top > 0 && (int) ($counts[$a->id] ?? 0) === $top
                        ? $authors->get($a->player_id)?->only(['nick', 'avatar'])
                        : null,
                ])
                // Each phone gets its own stable shuffle, so no answer wins by being listed first
                // and the order doesn't give away who submitted when.
                ->sortBy(fn (array $a) => $reveal ? -$a['votes'] : crc32(($player?->id ?? 0).':'.$a['id']))
                ->values();
        }

        if (in_array($game->phase, ['reveal', 'end'])) {
            $players = $game->players()->orderBy('id')->get();
            $tally = $this->votesByPlayerAndRound($game);
            $now = $this->ranked($players, $tally, $round);

            if ($game->phase === 'reveal') {
                $before = $round > 0 ? $this->ranked($players, $tally, $round - 1)->pluck('rank', 'id') : collect();
                $standings = $now->map(fn (array $row) => [
                    'rank' => $row['rank'],
                    'nick' => $row['nick'],
                    'avatar' => $row['avatar'],
                    'score' => $row['score'],
                    'move' => $before->has($row['id']) ? $before[$row['id']] - $row['rank'] : 0,
                    'me' => $player !== null && $row['id'] === $player->id,
                ]);
                $out['standings'] = $standings->take(5)->values();
                $out['myStanding'] = $standings->firstWhere('me', true);
            } else {
                $out['board'] = $now->map(fn (array $row) => [
                    'nick' => $row['nick'],
                    'avatar' => $row['avatar'],
                    'score' => $row['score'],
                    'me' => $player !== null && $row['id'] === $player->id,
                ])->values();
                $out['awards'] = $this->awards($game, $players, $tally);
            }
        }

        return response()->json($out);
    }

    /**
     * Votes each player's answers received, as [player id => [round => votes]].
     */
    private function votesByPlayerAndRound(Game $game): Collection
    {
        return Vote::query()
            ->join('answers', 'answers.id', '=', 'votes.answer_id')
            ->where('votes.game_id', $game->id)
            ->selectRaw('answers.player_id as player_id, votes.round as round, count(*) as n')
            ->groupBy('answers.player_id', 'votes.round')
            ->get()
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [(int) $row->round => (int) $row->n]));
    }

    /**
     * Players in scoreboard order, counting rounds up to and including $throughRound.
     * Every vote is worth 100, and tied players share a rank.
     */
    private function ranked(Collection $players, Collection $tally, int $throughRound): Collection
    {
        $rows = $players
            ->map(fn (Player $p) => [
                'id' => $p->id,
                'nick' => $p->nick,
                'avatar' => $p->avatar,
                'score' => 100 * collect($tally->get($p->id, []))->filter(fn (int $n, int $round) => $round <= $throughRound)->sum(),
            ])
            ->sortBy([['score', 'desc'], ['id', 'asc']])
            ->values();

        return $rows->map(fn (array $row) => $row + [
            'rank' => 1 + $rows->filter(fn (array $other) => $other['score'] > $row['score'])->count(),
        ]);
    }

    /**
     * Extra moments for the final screen, so more than the top three get one.
     */
    private function awards(Game $game, Collection $players, Collection $tally): array
    {
        $players = $players->keyBy('id');
        $card = fn (string $title, int $playerId, string $detail, ?string $text = null) => [
            'title' => $title,
            'nick' => $players[$playerId]->nick,
            'avatar' => $players[$playerId]->avatar,
            'detail' => $detail,
            'text' => $text,
        ];
        $awards = [];

        $best = $game->votes()->selectRaw('answer_id, count(*) as n')->groupBy('answer_id')->orderByDesc('n')->orderBy('answer_id')->first();
        $answer = $best ? $game->answers()->find($best->answer_id) : null;
        if ($answer && $players->has($answer->player_id)) {
            $awards[] = $card(
                'Answer of the game',
                $answer->player_id,
                $best->n.' '.($best->n == 1 ? 'vote' : 'votes').' in round '.($answer->round + 1),
                $answer->text,
            );
        }

        $roundsScored = $tally->map(fn (Collection $rounds) => $rounds->count())->sortDesc();
        if ($roundsScored->isNotEmpty() && $roundsScored->first() >= 2 && $players->has($roundsScored->keys()->first())) {
            $awards[] = $card(
                'Most consistent',
                $roundsScored->keys()->first(),
                'Earned votes in '.$roundsScored->first().' of '.($game->round + 1).' rounds',
            );
        }

        if ($game->round >= 3) {
            $late = $tally->map(fn (Collection $rounds) => $rounds->filter(fn (int $n, int $round) => $round > $game->round - 3)->sum())->sortDesc();
            if ($late->isNotEmpty() && $late->first() > 0 && $players->has($late->keys()->first())) {
                $awards[] = $card(
                    'Strong finish',
                    $late->keys()->first(),
                    $late->first().' '.($late->first() == 1 ? 'vote' : 'votes').' in the last three rounds',
                );
            }
        }

        return $awards;
    }

    /**
     * Two people picking the same name get "Sam" and "Sam 2", so the scoreboard stays readable.
     */
    private function uniqueNick(Game $game, string $token, string $nick): string
    {
        $taken = $game->players()->where('token', '!=', $token)->pluck('nick')->map(fn (string $n) => mb_strtolower($n));
        $candidate = $nick;
        for ($n = 2; $taken->contains(mb_strtolower($candidate)); $n++) {
            $candidate = mb_substr($nick, 0, 20 - strlen(" $n"))." $n";
        }

        return $candidate;
    }

    private function token(Request $request): ?string
    {
        $token = (string) $request->header('X-Player', '');

        return preg_match('/^[A-Za-z0-9]{16,64}$/', $token) ? $token : null;
    }

    private function player(Request $request, Game $game): ?Player
    {
        $token = $this->token($request);

        return $token ? $game->players()->where('token', $token)->first() : null;
    }

    private function isHost(Request $request): bool
    {
        $key = (string) config('game.host_key');

        return $key !== '' && hash_equals($key, (string) $request->header('X-Host-Key', ''));
    }

    private function authorizeHost(Request $request): void
    {
        abort_unless($this->isHost($request), 403, 'That host key is not right.');
    }
}
