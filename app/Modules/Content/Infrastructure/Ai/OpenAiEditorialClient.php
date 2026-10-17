<?php

namespace App\Modules\Content\Infrastructure\Ai;

use App\Modules\Content\Application\Contracts\EditorialAiClient;
use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Data\EditorialResultData;
use App\Modules\Content\Application\Exceptions\EditorialException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class OpenAiEditorialClient implements EditorialAiClient
{
    public function available(): bool
    {
        return trim((string) config('services.content_ai.api_key', '')) !== '';
    }

    public function edit(EditorialRequestData $request): EditorialResultData
    {
        if (! $this->available()) {
            throw new EditorialException('provider_not_configured', 'OpenAI API key is not configured.');
        }

        $config = $this->config();
        $response = $this->client($config)->post(
            rtrim($config['base_url'], '/').'/responses',
            [
                'model' => $config['model'],
                'instructions' => $this->instructions(),
                'input' => $this->input($request),
                'max_output_tokens' => $config['max_output_tokens'],
                'text' => ['format' => $this->format()],
            ],
        );

        if (! $response->successful()) {
            throw new EditorialException(
                'provider_request_failed',
                'OpenAI editorial request failed with HTTP '.$response->status().'.',
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EditorialException('provider_invalid_response', 'OpenAI returned invalid JSON.');
        }
        if (($payload['status'] ?? null) !== 'completed') {
            throw new EditorialException(
                'provider_incomplete_response',
                'OpenAI editorial response did not complete successfully.',
            );
        }

        $text = $this->outputText($payload);
        if ($text === null) {
            if ($this->hasRefusal($payload)) {
                throw new EditorialException('provider_refused', 'OpenAI refused the editorial request.');
            }

            throw new EditorialException('provider_invalid_response', 'OpenAI response did not include output text.');
        }

        $result = json_decode($text, true);
        if (! is_array($result)
            || ! is_string($result['title'] ?? null)
            || ! is_array($result['blocks'] ?? null)
            || ! is_array($result['change_summary'] ?? null)
            || ! is_array($result['verification_required'] ?? null)) {
            throw new EditorialException('provider_invalid_response', 'OpenAI editorial JSON did not match the expected schema.');
        }

        return new EditorialResultData(
            provider: 'openai',
            model: $config['model'],
            title: trim($result['title']),
            blocks: $result['blocks'],
            changeSummary: array_values($result['change_summary']),
            verificationRequired: array_values($result['verification_required']),
        );
    }

    /** @return array{api_key:string,base_url:string,model:string,timeout:int,max_output_tokens:int,proxy:string} */
    private function config(): array
    {
        return [
            'api_key' => trim((string) config('services.content_ai.api_key', '')),
            'base_url' => (string) config('services.content_ai.base_url', 'https://api.openai.com/v1'),
            'model' => (string) config('services.content_ai.model', 'gpt-5.6-terra'),
            'timeout' => max(10, (int) config('services.content_ai.timeout', 90)),
            'max_output_tokens' => max(1000, (int) config('services.content_ai.max_output_tokens', 12000)),
            'proxy' => trim((string) config('services.content_ai.proxy', '')),
        ];
    }

    /** @param array{api_key:string,base_url:string,model:string,timeout:int,max_output_tokens:int,proxy:string} $config */
    private function client(array $config): PendingRequest
    {
        $client = Http::withToken($config['api_key'])
            ->acceptJson()
            ->timeout($config['timeout']);

        if ($config['proxy'] !== '') {
            $client = $client->withOptions(['proxy' => $config['proxy']]);
        }

        return $client;
    }

    private function instructions(): string
    {
        return <<<'TEXT'
You are the editorial stage of a controlled B2B content production workflow.
Edit the supplied accepted source into publication-quality content while preserving its factual meaning.
Use only facts present in the accepted source, guideline, or supplied evidence. Never invent product specifications, certifications, prices, quantified performance, field results, citations, URLs, or competitor claims.
If a factual statement is uncertain or requires outside verification, either remove/soften it or add it to verification_required.
Preserve the requested language, search intent, useful technical detail, and brand tone. Improve structure, clarity, grammar, repetition, headings, and CTA quality.
Return only the required structured JSON. Do not include hidden reasoning.
TEXT;
    }

    private function input(EditorialRequestData $request): string
    {
        return json_encode([
            'article' => [
                'language' => $request->language,
                'content_type' => $request->contentType,
                'title_direction' => $request->titleDirection,
            ],
            'guideline' => $request->guideline,
            'accepted_source' => [
                'type' => $request->sourceType,
                'version' => $request->sourceVersion,
                'sha256' => $request->sourceHash,
                'normalized_content' => $request->sourceNormalizedJson,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function format(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'content_editorial_v1',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'blocks', 'change_summary', 'verification_required'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['type', 'level', 'text', 'items'],
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['heading', 'paragraph', 'bulleted_list', 'numbered_list'],
                                ],
                                'level' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 6],
                                'text' => ['type' => 'string'],
                                'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],

                    'change_summary' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'verification_required' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['claim', 'reason'],
                            'properties' => [
                                'claim' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function outputText(array $payload): ?string
    {
        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            return $payload['output_text'];
        }

        foreach (($payload['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content)
                    && ($content['type'] ?? null) === 'output_text'
                    && is_string($content['text'] ?? null)
                    && trim($content['text']) !== '') {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function hasRefusal(array $payload): bool
    {
        foreach (($payload['output'] ?? []) as $item) {
            foreach ((is_array($item) ? ($item['content'] ?? []) : []) as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'refusal') {
                    return true;
                }
            }
        }

        return false;
    }
}
