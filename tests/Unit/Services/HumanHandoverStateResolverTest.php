<?php

namespace Iquesters\SmartMessenger\Tests\Unit\Services;

use Iquesters\SmartMessenger\Tests\TestCase;
use Iquesters\SmartMessenger\Services\HumanHandoverStateResolver;

class HumanHandoverStateResolverTest extends TestCase
{
    /** @test */
    public function it_parses_the_canonical_nested_active_handover_state(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $state = $resolver->resolve([
            [
                'ccx_state_snapshot' => [
                    'payload' => [
                        'human_handover' => [
                            'active' => true,
                            'since_utc' => '2026-05-02T18:30:00Z',
                            'reason' => 'explicit_human_agent_request',
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($state['active']);
        $this->assertSame('2026-05-02T18:30:00Z', $state['hand_over_time']);
        $this->assertSame('explicit_human_agent_request', $state['reason']);
        $this->assertSame('active', $state['status']);
        $this->assertSame('[0].ccx_state_snapshot.payload.human_handover', $state['raw_path']);
    }

    /** @test */
    public function it_parses_the_canonical_nested_inactive_handover_state(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $state = $resolver->resolve([
            [
                'ccx_state_snapshot' => [
                    'payload' => [
                        'human_handover' => [
                            'active' => false,
                            'since_utc' => '2026-05-02T18:30:00Z',
                            'ended_utc' => '2026-05-02T19:00:00Z',
                            'reason' => 'agent_returned_control_to_bot',
                            'status' => 'closed',
                            'ended_by' => 'agent',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($state['active']);
        $this->assertSame('2026-05-02T18:30:00Z', $state['hand_over_time']);
        $this->assertSame('2026-05-02T19:00:00Z', $state['ended_utc']);
        $this->assertSame('agent_returned_control_to_bot', $state['reason']);
        $this->assertSame('closed', $state['status']);
        $this->assertSame('agent', $state['ended_by']);
    }

    /** @test */
    public function latest_entry_wins_over_older_entries(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $state = $resolver->resolve([
            [
                'ccx_state_snapshot' => [
                    'payload' => [
                        'human_handover' => [
                            'active' => true,
                            'since_utc' => '2026-05-02T18:30:00Z',
                            'reason' => 'initial_handover',
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
            [
                'ccx_state_snapshot' => [
                    'payload' => [
                        'human_handover' => [
                            'active' => false,
                            'since_utc' => '2026-05-02T18:30:00Z',
                            'ended_utc' => '2026-05-02T19:00:00Z',
                            'reason' => 'agent_returned_control_to_bot',
                            'status' => 'closed',
                            'ended_by' => 'agent',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($state['active']);
        $this->assertSame('agent_returned_control_to_bot', $state['reason']);
        $this->assertSame('2026-05-02T19:00:00Z', $state['ended_utc']);
        $this->assertSame('[1].ccx_state_snapshot.payload.human_handover', $state['raw_path']);
    }

    /** @test */
    public function it_tolerates_the_legacy_flat_handover_shape(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $state = $resolver->resolve([
            'human_handover' => true,
            'hand_over_time' => '2026-05-02T18:30:00Z',
            'reason' => 'legacy_handover',
        ]);

        $this->assertTrue($state['active']);
        $this->assertSame('2026-05-02T18:30:00Z', $state['hand_over_time']);
        $this->assertSame('legacy_handover', $state['reason']);
        $this->assertSame('human_handover', $state['raw_path']);
    }

    /** @test */
    public function malformed_json_returns_an_inactive_state(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $state = $resolver->resolve('{"broken": ');

        $this->assertFalse($state['active']);
        $this->assertNull($state['hand_over_time']);
        $this->assertNull($state['raw_path']);
    }

    /** @test */
    public function null_or_empty_context_returns_an_inactive_state(): void
    {
        $resolver = app(HumanHandoverStateResolver::class);

        $nullState = $resolver->resolve(null);
        $emptyState = $resolver->resolve([]);

        $this->assertFalse($nullState['active']);
        $this->assertFalse($emptyState['active']);
    }
}
