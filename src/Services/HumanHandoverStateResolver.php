<?php

namespace Iquesters\SmartMessenger\Services;

use Throwable;
use Iquesters\Foundation\System\Traits\Loggable;

class HumanHandoverStateResolver
{
    use Loggable;

    public function resolve($contextJson): array
    {
        $this->logMethodStart('Resolving human handover state');

        try {
            $normalized = $this->normalizeRoot($contextJson);

            if ($normalized === null || $normalized === []) {
                return $this->inactiveState();
            }

            $state = $this->findLatestState($normalized);

            if (!$state) {
                return $this->inactiveState();
            }

            $this->logInfo('Handover state parsed successfully' . $this->ctx([
                'active' => $state['active'],
                'hand_over_time' => $state['hand_over_time'],
                'reason' => $state['reason'],
                'status' => $state['status'],
                'ended_utc' => $state['ended_utc'],
                'ended_by' => $state['ended_by'],
                'raw_path' => $state['raw_path'],
            ]));

            return $state;
        } finally {
            $this->logMethodEnd('Human handover resolution complete');
        }
    }

    private function normalizeRoot($contextJson)
    {
        if ($contextJson === null || $contextJson === '') {
            return [];
        }

        if (is_string($contextJson)) {
            try {
                $decoded = json_decode($contextJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $this->logWarning('Malformed context_json encountered while resolving handover state' . $this->ctx([
                    'error' => $e->getMessage(),
                ]));

                return [];
            }

            return $this->normalizeNode($decoded);
        }

        return $this->normalizeNode($contextJson);
    }

    private function normalizeNode($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?? [];
        }

        return $value;
    }

    private function findLatestState($node, string $path = '')
    {
        $node = $this->normalizeNode($node);

        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node)) {
            for ($index = count($node) - 1; $index >= 0; $index--) {
                $childPath = $path === '' ? "[{$index}]" : "{$path}[{$index}]";
                $state = $this->findLatestState($node[$index], $childPath);

                if ($state !== null) {
                    return $state;
                }
            }

            return null;
        }

        $candidates = [
            [
                'path' => $this->joinPath($path, 'ccx_state_snapshot.payload.human_handover'),
                'value' => data_get($node, 'ccx_state_snapshot.payload.human_handover'),
                'container' => data_get($node, 'ccx_state_snapshot.payload', []),
            ],
            [
                'path' => $this->joinPath($path, 'payload.human_handover'),
                'value' => data_get($node, 'payload.human_handover'),
                'container' => data_get($node, 'payload', []),
            ],
            [
                'path' => $this->joinPath($path, 'human_handover'),
                'value' => $node['human_handover'] ?? null,
                'container' => $node,
            ],
        ];

        foreach ($candidates as $candidate) {
            $state = $this->normalizeCandidate(
                $candidate['value'],
                $candidate['container'],
                $candidate['path']
            );

            if ($state !== null) {
                return $state;
            }
        }

        if (array_key_exists('human_handover', $node)) {
            $legacy = $this->normalizeLegacyFlatState($node, $this->joinPath($path, 'human_handover'));

            if ($legacy !== null) {
                return $legacy;
            }
        }

        return null;
    }

    private function normalizeCandidate($candidate, $container, string $path): ?array
    {
        if ($candidate === null) {
            return null;
        }

        $container = $this->normalizeNode($container);
        $candidate = $this->normalizeNode($candidate);

        if (is_array($candidate)) {
            $active = $this->normalizeBool($candidate['active'] ?? null);

            if ($active === null && !array_key_exists('active', $candidate)) {
                return null;
            }

            return [
                'active' => $active ?? false,
                'hand_over_time' => $this->toNullableString($candidate['since_utc'] ?? $candidate['hand_over_time'] ?? null),
                'reason' => $this->toNullableString($candidate['reason'] ?? null),
                'status' => $this->toNullableString($candidate['status'] ?? null),
                'ended_utc' => $this->toNullableString($candidate['ended_utc'] ?? null),
                'ended_by' => $this->toNullableString($candidate['ended_by'] ?? null),
                'raw_path' => $path,
            ];
        }

        $legacy = $this->normalizeBool($candidate);

        if ($legacy === null) {
            return null;
        }

        return [
            'active' => $legacy,
            'hand_over_time' => $this->toNullableString(is_array($container) ? ($container['hand_over_time'] ?? $container['since_utc'] ?? null) : null),
            'reason' => $this->toNullableString(is_array($container) ? ($container['reason'] ?? null) : null),
            'status' => $this->toNullableString(is_array($container) ? ($container['status'] ?? null) : null),
            'ended_utc' => $this->toNullableString(is_array($container) ? ($container['ended_utc'] ?? null) : null),
            'ended_by' => $this->toNullableString(is_array($container) ? ($container['ended_by'] ?? null) : null),
            'raw_path' => $path,
        ];
    }

    private function normalizeLegacyFlatState(array $node, string $path): ?array
    {
        $active = $this->normalizeBool($node['human_handover'] ?? null);

        if ($active === null) {
            return null;
        }

        return [
            'active' => $active,
            'hand_over_time' => $this->toNullableString($node['hand_over_time'] ?? $node['since_utc'] ?? null),
            'reason' => $this->toNullableString($node['reason'] ?? null),
            'status' => $this->toNullableString($node['status'] ?? null),
            'ended_utc' => $this->toNullableString($node['ended_utc'] ?? null),
            'ended_by' => $this->toNullableString($node['ended_by'] ?? null),
            'raw_path' => $path,
        ];
    }

    private function normalizeBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                '1', 'true', 'yes', 'active' => true,
                '0', 'false', 'no', 'inactive', 'closed' => false,
                default => null,
            };
        }

        return null;
    }

    private function toNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }

    private function joinPath(string $prefix, string $suffix): string
    {
        return $prefix === '' ? $suffix : "{$prefix}.{$suffix}";
    }

    private function inactiveState(): array
    {
        return [
            'active' => false,
            'hand_over_time' => null,
            'reason' => null,
            'status' => null,
            'ended_utc' => null,
            'ended_by' => null,
            'raw_path' => null,
        ];
    }

    private function ctx(array $context): string
    {
        return ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
