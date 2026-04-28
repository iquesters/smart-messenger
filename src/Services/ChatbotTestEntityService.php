<?php

namespace Iquesters\SmartMessenger\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ChatbotTestEntityService
{
    public const CASES_TABLE = 'chatbot_test_cases';
    public const RUNS_TABLE = 'chatbot_test_runs';
    public const RUN_ITEMS_TABLE = 'chatbot_test_run_items';

    public function assertRunnerSchemaReady(): void
    {
        foreach ([self::CASES_TABLE, self::RUNS_TABLE, self::RUN_ITEMS_TABLE] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Required chatbot test table '{$table}' was not found.");
            }
        }

        foreach ([
            self::RUNS_TABLE => ['run_uid'],
            self::RUN_ITEMS_TABLE => ['chatbot_test_run_uid', 'chatbot_test_case_uid'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Required column '{$table}.{$column}' was not found.");
                }
            }
        }

        foreach ([self::CASES_TABLE, self::RUNS_TABLE, self::RUN_ITEMS_TABLE] as $table) {
            if (!Schema::hasColumn($table, 'status')) {
                throw new RuntimeException("The chatbot test flow requires a 'status' column on '{$table}'.");
            }
        }
    }

    public function getActiveCases(array $caseUids = []): Collection
    {
        $query = DB::table(self::CASES_TABLE)
            ->where('status', 'active')
            ->orderBy($this->hasColumn(self::CASES_TABLE, 'sort_order') ? 'sort_order' : 'id')
            ->orderBy('id');

        if (!empty($caseUids) && $this->hasColumn(self::CASES_TABLE, 'uid')) {
            $query->whereIn('uid', $caseUids);
        }

        return $this->attachMeta(self::CASES_TABLE, $query->get());
    }

    public function createRun(int $channelId, int $userId, int $intervalMinutes, Collection $cases): object
    {
        $runUid = (string) Str::ulid();
        $timestamp = now();

        $payload = $this->buildInsertPayload(self::RUNS_TABLE, [
            'run_uid' => $runUid,
            'channel_id' => $channelId,
            'started_at_custom' => null,
            'completed_at_custom' => null,
            'last_dispatched_at' => null,
            'next_dispatch_at' => null,
            'last_index' => 0,
            'total_cases' => $cases->count(),
            'processed_cases' => 0,
            'passed_cases' => 0,
            'failed_cases' => 0,
            'status' => 'pending',
            'triggered_by' => $userId,
        ], $userId, $timestamp);

        $runId = DB::table(self::RUNS_TABLE)->insertGetId($payload);
        $this->upsertMeta(self::RUNS_TABLE, $runId, [
            'config' => [
                'interval_minutes' => $intervalMinutes,
                'selected_case_uids' => $cases->pluck('uid')->filter()->values()->all(),
            ],
            'summary' => null,
        ], $userId, $timestamp);

        foreach ($cases->values() as $index => $case) {
            $itemPayload = $this->buildInsertPayload(self::RUN_ITEMS_TABLE, [
                'chatbot_test_run_uid' => $runUid,
                'chatbot_test_case_uid' => $case->uid,
                'sequence_no' => $index + 1,
                'question' => $case->question,
                'expected_answer' => $case->expected_answer ?? null,
                'expected_contains' => $case->expected_contains ?? null,
                'identifier' => $this->hasColumn(self::RUN_ITEMS_TABLE, 'identifier') ? '9999999999' : null,
                'contact_name' => $this->hasColumn(self::RUN_ITEMS_TABLE, 'contact_name') ? 'Test Man' : null,
                'status' => 'pending',
            ], $userId, $timestamp);

            $itemId = DB::table(self::RUN_ITEMS_TABLE)->insertGetId($itemPayload);
            $this->upsertMeta(self::RUN_ITEMS_TABLE, $itemId, [
                'assertion_result' => null,
            ], $userId, $timestamp);
        }

        return $this->getRunByRunUid($runUid);
    }

    public function getRunByRunUid(string $runUid): ?object
    {
        $record = DB::table(self::RUNS_TABLE)->where('run_uid', $runUid)->first();
        if (!$record) {
            return null;
        }

        return $this->attachMeta(self::RUNS_TABLE, collect([$record]))->first();
    }

    public function getRunItems(string $runUid): Collection
    {
        $items = DB::table(self::RUN_ITEMS_TABLE)
            ->where('chatbot_test_run_uid', $runUid)
            ->orderBy($this->hasColumn(self::RUN_ITEMS_TABLE, 'sequence_no') ? 'sequence_no' : 'id')
            ->get();

        return $this->attachMeta(self::RUN_ITEMS_TABLE, $items);
    }

    public function getNextPendingRunItem(string $runUid): ?object
    {
        $record = DB::table(self::RUN_ITEMS_TABLE)
            ->where('chatbot_test_run_uid', $runUid)
            ->where('status', 'pending')
            ->orderBy($this->hasColumn(self::RUN_ITEMS_TABLE, 'sequence_no') ? 'sequence_no' : 'id')
            ->first();

        if (!$record) {
            return null;
        }

        return $this->attachMeta(self::RUN_ITEMS_TABLE, collect([$record]))->first();
    }

    public function updateRunByRunUid(string $runUid, array $data = [], array $meta = [], ?int $userId = null): void
    {
        $record = DB::table(self::RUNS_TABLE)->where('run_uid', $runUid)->first();
        if (!$record) {
            throw new RuntimeException("Run '{$runUid}' was not found.");
        }

        $payload = $this->filterColumns(self::RUNS_TABLE, $data);
        $payload = $this->applyUpdateAuditDefaults(self::RUNS_TABLE, $payload, $userId ?? 0);

        if (!empty($payload)) {
            DB::table(self::RUNS_TABLE)->where('id', $record->id)->update($payload);
        }

        if (!empty($meta)) {
            $this->upsertMeta(self::RUNS_TABLE, (int) $record->id, $meta, $userId ?? 0);
        }
    }

    public function updateRunItemByUid(string $uid, array $data = [], array $meta = [], ?int $userId = null): void
    {
        $referenceColumn = $this->hasColumn(self::RUN_ITEMS_TABLE, 'uid') ? 'uid' : 'id';
        $record = DB::table(self::RUN_ITEMS_TABLE)->where($referenceColumn, $uid)->first();
        if (!$record) {
            throw new RuntimeException("Run item '{$uid}' was not found.");
        }

        $payload = $this->filterColumns(self::RUN_ITEMS_TABLE, $data);
        $payload = $this->applyUpdateAuditDefaults(self::RUN_ITEMS_TABLE, $payload, $userId ?? 0);

        if (!empty($payload)) {
            DB::table(self::RUN_ITEMS_TABLE)->where('id', $record->id)->update($payload);
        }

        if (!empty($meta)) {
            $this->upsertMeta(self::RUN_ITEMS_TABLE, (int) $record->id, $meta, $userId ?? 0);
        }
    }

    public function cancelRun(string $runUid, int $userId = 0): void
    {
        $this->updateRunByRunUid($runUid, [
            'status' => 'cancelled',
            'completed_at_custom' => now(),
            'next_dispatch_at' => null,
        ], [], $userId);
    }

    public function refreshRunCounters(string $runUid, int $userId = 0): array
    {
        $base = DB::table(self::RUN_ITEMS_TABLE)->where('chatbot_test_run_uid', $runUid);
        $processed = (clone $base)->whereIn('status', ['passed', 'failed'])->count();
        $passed = (clone $base)->where('status', 'passed')->count();
        $failed = (clone $base)->where('status', 'failed')->count();

        $this->updateRunByRunUid($runUid, [
            'processed_cases' => $processed,
            'passed_cases' => $passed,
            'failed_cases' => $failed,
        ], [], $userId);

        return [
            'processed_cases' => $processed,
            'passed_cases' => $passed,
            'failed_cases' => $failed,
        ];
    }

    public function attachMeta(string $table, Collection $records): Collection
    {
        $metaTable = $this->resolveMetaTable($table);
        if (!$metaTable || $records->isEmpty()) {
            return $records->map(function ($record) {
                $record->meta = [];
                return $record;
            });
        }

        $ids = $records->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return $records;
        }

        $metaRows = DB::table($metaTable)->whereIn('ref_parent', $ids)->get();
        $grouped = $metaRows->groupBy('ref_parent');

        return $records->map(function ($record) use ($grouped) {
            $metaRows = $grouped->get($record->id, collect());
            $meta = [];
            foreach ($metaRows as $metaRow) {
                $meta[$metaRow->meta_key] = $this->decodeMetaValue($metaRow->meta_value);
            }
            $record->meta = $meta;
            return $record;
        });
    }

    public function resolveMetaTable(string $table): ?string
    {
        $possible = [$table . '_metas', $table . '_meta', Str::singular($table) . '_meta', Str::singular($table) . '_metas'];
        foreach ($possible as $candidate) {
            if (Schema::hasTable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    protected function buildInsertPayload(string $table, array $data, int $userId = 0, $timestamp = null): array
    {
        $timestamp = $timestamp ?? now();
        $payload = $this->filterColumns($table, $data);
        $columns = Schema::getColumnListing($table);

        if (in_array('uid', $columns, true) && empty($payload['uid'])) {
            $payload['uid'] = (string) Str::ulid();
        }

        foreach (['created_by' => $userId, 'updated_by' => $userId, 'created_at' => $timestamp, 'updated_at' => $timestamp] as $column => $value) {
            if (in_array($column, $columns, true) && !array_key_exists($column, $payload)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }

    protected function applyUpdateAuditDefaults(string $table, array $payload, int $userId = 0): array
    {
        $columns = Schema::getColumnListing($table);
        if (in_array('updated_by', $columns, true) && !array_key_exists('updated_by', $payload)) {
            $payload['updated_by'] = $userId;
        }
        if (in_array('updated_at', $columns, true) && !array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = now();
        }
        return $payload;
    }

    protected function filterColumns(string $table, array $data): array
    {
        $columns = Schema::getColumnListing($table);
        return collect($data)->filter(fn ($value, $key) => in_array($key, $columns, true))->all();
    }

    protected function upsertMeta(string $table, int $recordId, array $meta, int $userId = 0, $timestamp = null): void
    {
        $metaTable = $this->resolveMetaTable($table);
        if (!$metaTable || empty($meta)) {
            return;
        }

        $timestamp = $timestamp ?? now();
        $columns = Schema::getColumnListing($metaTable);

        foreach ($meta as $key => $value) {
            $payload = ['meta_value' => $this->normalizeMetaValue($value)];
            foreach (['status' => 'active', 'updated_by' => $userId, 'updated_at' => $timestamp] as $column => $columnValue) {
                if (in_array($column, $columns, true)) {
                    $payload[$column] = $columnValue;
                }
            }

            if (!DB::table($metaTable)->where('ref_parent', $recordId)->where('meta_key', $key)->exists()) {
                foreach (['created_by' => $userId, 'created_at' => $timestamp] as $column => $columnValue) {
                    if (in_array($column, $columns, true)) {
                        $payload[$column] = $columnValue;
                    }
                }
            }

            DB::table($metaTable)->updateOrInsert(['ref_parent' => $recordId, 'meta_key' => $key], $payload);
        }
    }

    protected function normalizeMetaValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    protected function decodeMetaValue($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}