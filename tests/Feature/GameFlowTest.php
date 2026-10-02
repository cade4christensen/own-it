<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ANA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        config(['game.host_key' => 'secret']);
    }

    private function as(string $token): static
    {
        return $this->withHeaders(['X-Player' => $token, 'X-Host-Key' => '']);
    }

    private function asHost(): static
    {
        return $this->withHeaders(['X-Player' => '', 'X-Host-Key' => 'secret']);
    }

    public function test_a_full_round_stays_anonymous_and_scores_votes(): void
    {
        $this->as(self::ANA)->postJson('/api/join', ['nick' => 'Ana', 'avatar' => 'owl'])->assertOk()->assertJsonPath('me.nick', 'Ana')->assertJsonPath('roster.0.avatar', 'owl');
        $this->as(self::BEN)->postJson('/api/join', ['nick' => 'Ben', 'avatar' => 'not-an-avatar'])->assertStatus(422);
        $this->as(self::BEN)->postJson('/api/join', ['nick' => 'Ben'])->assertOk()->assertJsonPath('players', 2);

        $this->asHost()->postJson('/api/host/advance', ['action' => 'start'])->assertOk()->assertJsonPath('game.phase', 'write');

        $this->as(self::ANA)->postJson('/api/answer', ['text' => 'I missed it. Fixing it by noon.'])->assertOk();
        $write = $this->as(self::BEN)->postJson('/api/answer', ['text' => 'That one is on me.'])->assertOk();
        $write->assertJsonPath('answerCount', 2)->assertJsonMissingPath('answers');

        $vote = $this->asHost()->postJson('/api/host/advance', ['action' => 'vote'])->assertOk();
        $vote->assertJsonCount(2, 'answers')->assertJsonMissingPath('answers.0.player_id');
        $this->assertSame([0, 0], array_column($vote->json('answers'), 'votes'));

        $anaAnswer = $this->as(self::ANA)->getJson('/api/state')->json('me.answerId');
        $this->as(self::ANA)->postJson('/api/vote', ['answer_id' => $anaAnswer])->assertStatus(422);
        $this->as(self::BEN)->postJson('/api/vote', ['answer_id' => $anaAnswer])->assertOk()->assertJsonPath('me.vote', $anaAnswer);

        $reveal = $this->asHost()->postJson('/api/host/advance', ['action' => 'reveal'])->assertOk();
        $reveal->assertJsonPath('answers.0.id', $anaAnswer)->assertJsonPath('answers.0.votes', 1);
        $reveal->assertJsonPath('answers.0.author.nick', 'Ana')->assertJsonPath('answers.1.author', null);
        $reveal->assertJsonPath('standings.0.nick', 'Ana')->assertJsonPath('standings.0.score', 100)->assertJsonPath('standings.1.rank', 2);

        $end = $this->asHost()->postJson('/api/host/advance', ['action' => 'end'])->assertOk();
        $end->assertJsonPath('board.0.nick', 'Ana')->assertJsonPath('board.0.avatar', 'owl')->assertJsonPath('board.0.score', 100)->assertJsonPath('board.1.score', 0);
        $end->assertJsonPath('awards.0.title', 'Answer of the game')->assertJsonPath('awards.0.nick', 'Ana')->assertJsonPath('awards.0.text', 'I missed it. Fixing it by noon.');
    }

    public function test_only_the_host_key_can_run_the_game(): void
    {
        $this->as(self::ANA)->postJson('/api/host/advance', ['action' => 'start'])->assertForbidden();
        $this->as(self::ANA)->getJson('/api/state')->assertOk()->assertJsonPath('isHost', false)->assertJsonMissingPath('prompts');

        config(['game.host_key' => '']);
        $this->asHost()->postJson('/api/host/advance', ['action' => 'start'])->assertForbidden();
    }

    public function test_answers_close_when_the_round_leaves_the_write_phase(): void
    {
        $this->as(self::ANA)->postJson('/api/join', ['nick' => 'Ana']);
        $this->as(self::ANA)->postJson('/api/answer', ['text' => 'Too early'])->assertStatus(422);

        $this->asHost()->postJson('/api/host/advance', ['action' => 'start']);
        $this->asHost()->postJson('/api/host/advance', ['action' => 'vote']);
        $this->as(self::ANA)->postJson('/api/answer', ['text' => 'Too late'])->assertStatus(422);
    }

    public function test_a_new_game_keeps_the_prompts_and_clears_the_players(): void
    {
        $this->asHost()->postJson('/api/host/prompts', ['items' => [
            ['setup' => 'Late report.', 'line' => 'Nobody reminded me.'],
            ['setup' => 'You find a mistake nobody noticed.', 'ask' => 'What do you do?'],
            ['setup' => '', 'line' => ''],
        ]])->assertOk()->assertJsonPath('game.total', 2)->assertJsonPath('prompts.1.ask', 'What do you do?');
        $this->as(self::ANA)->postJson('/api/join', ['nick' => 'Ana']);

        $this->asHost()->postJson('/api/host/advance', ['action' => 'new'])
            ->assertOk()->assertJsonPath('players', 0)->assertJsonPath('prompts.0.line', 'Nobody reminded me.');
    }
}
