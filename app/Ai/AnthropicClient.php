<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal client of the Anthropic Messages API: a prompt with optional images, answered by a JSON object.
 * The API key comes from the environment (empty = the AI features are off); it is never logged.
 */
class AnthropicClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    /**
     * Asks the model and returns the first JSON object of its answer.
     *
     * @param  list<array{data: string, media_type: string}>  $images  Base64 images, in the order the prompt refers to them.
     * @param  int|null  $timeout  Seconds to wait for the answer (default from the configuration).
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function askForJson(string $prompt, array $images, int $maxTokens, ?int $timeout = null): array
    {
        if (! $this->isConfigured()) {
            throw new AiException('not_configured');
        }

        $content = [];

        foreach ($images as $image) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']]];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        try {
            $response = Http::timeout($timeout ?? (int) config('services.anthropic.timeout'))
                ->withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => (string) config('services.anthropic.version')])
                ->post((string) config('services.anthropic.url'), ['model' => config('services.anthropic.model'), 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $content]]]);
        } catch (ConnectionException) {
            throw new AiException('unavailable');
        }

        if (! $response->successful()) {
            Log::warning('Anthropic request failed', ['status' => $response->status()]);

            throw new AiException('unavailable');
        }

        $text = '';

        foreach ((array) $response->json('content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if (preg_match('/\{.*\}/s', $text, $match) !== 1) {
            throw new AiException('unreadable_answer');
        }

        $data = json_decode($match[0], true);

        if (! is_array($data)) {
            throw new AiException('unreadable_answer');
        }

        return $data;
    }
}
