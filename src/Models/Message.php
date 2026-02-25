<?php

namespace Iquesters\SmartMessenger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Iquesters\Integration\Models\Integration;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'integration_id',
        'message_id',
        'from',
        'to',
        'message_type',
        'content',
        'timestamp',
        'status',
        'raw_payload',
        'raw_response',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'timestamp'     => 'datetime',
        'raw_payload'   => 'array',
        'raw_response'  => 'array',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
    
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    
    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
    
    public function getSenderNameAttribute(): string
    {
        $integrationName = $this->integration?->supportedIntegration?->name;

        if ($integrationName) {
            // convert "gautams-chatbot" → "Gautams Chatbot"
            return str($integrationName)
                ->replace('-', ' ')
                ->title();
        }

        return $this->creator?->name ?? 'System';
    }

    /**
     * Message has many meta entries
     */
    public function metas(): HasMany
    {
        return $this->hasMany(MessageMeta::class, 'ref_parent');
    }

    /**
     * Get meta value by key
     */
    public function getMeta(string $key): ?string
    {
        if ($this->relationLoaded('metas')) {
            $meta = $this->metas->firstWhere('meta_key', $key);
            if ($meta) {
                return (string) $meta->meta_value;
            }
        }

        return $this->metas()
            ->where('meta_key', $key)
            ->value('meta_value');
    }

    /**
     * Get normalized human handover summary payload (v1)
     */
    public function handoverSummary(): ?array
    {
        $rawSummary = $this->getMeta('handover_summary_v1');

        if (!$rawSummary) {
            return null;
        }

        $decoded = json_decode($rawSummary, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 'v1') {
            return null;
        }

        $turns = [];
        $rawTurns = $decoded['turns'] ?? [];

        if (is_array($rawTurns)) {
            foreach ($rawTurns as $turn) {
                if (!is_array($turn)) {
                    continue;
                }

                $userMessage = trim((string) ($turn['user_message'] ?? ''));
                $chatbotAnswer = trim((string) ($turn['chatbot_answer'] ?? ''));

                if ($userMessage === '' && $chatbotAnswer === '') {
                    continue;
                }

                $turns[] = [
                    'user_message' => $userMessage,
                    'chatbot_answer' => $chatbotAnswer,
                ];
            }
        }

        return [
            'version' => 'v1',
            'turns' => $turns,
            'full_conversation_summary' => trim((string) ($decoded['full_conversation_summary'] ?? '')),
            'handover_trigger_summary' => trim((string) ($decoded['handover_trigger_summary'] ?? '')),
            'agent_next_best_action' => trim((string) ($decoded['agent_next_best_action'] ?? '')),
        ];
    }

    /**
     * Set or update meta value
     */
    public function setMeta(string $key, string $value)
    {
        return $this->metas()->updateOrCreate(
            [
                'ref_parent' => $this->id,
                'meta_key'   => $key,
            ],
            [
                'meta_value' => $value,
            ]
        );
    }
    
    public function isText(): bool
    {
        return $this->message_type === 'text';
    }

    public function isMedia(): bool
    {
        return in_array($this->message_type, [
            'image',
            'video',
            'audio',
            'document'
        ]);
    }

    public function mediaUrl(): ?string
    {
        return $this->getMeta('media_url');
    }

    public function caption(): ?string
    {
        // outgoing messages store caption inside JSON
        if ($this->content && is_string($this->content)) {
            $decoded = json_decode($this->content, true);
            return $decoded['caption'] ?? null;
        }

        return null;
    }
    
    public function formattedCaption(): ?string
{
    $caption = $this->caption();

    if (!$caption) {
        return null;
    }

    return nl2br(
        preg_replace(
            '/(https?:\/\/[^\s]+)/',
            '<a href="$1" target="_blank" class="text-primary text-decoration-underline">$1</a>',
            e($caption)
        )
    );
}

}
