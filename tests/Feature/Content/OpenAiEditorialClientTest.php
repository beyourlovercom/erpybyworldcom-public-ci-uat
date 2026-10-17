<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Infrastructure\Ai\OpenAiEditorialClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiEditorialClientTest extends TestCase
{
    public function test_structured_editorial_response_is_parsed_and_request_contract_is_strict(): void
    {
        config()->set('services.content_ai', [
            'api_key' => 'test-key',
            'base_url' => 'https://api.openai.test/v1',
            'model' => 'gpt-test',
            'timeout' => 5,
            'max_output_tokens' => 4000,
            'proxy' => '',
        ]);

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'Edited title',
                            'blocks' => [[
                                'type' => 'paragraph',
                                'level' => null,
                                'text' => 'Edited content.',
                                'items' => [],
                            ]],
                            'change_summary' => ['Improved structure.'],
                            'verification_required' => [[
                                'claim' => 'Exact performance result',
                                'reason' => 'Not supported by accepted source.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200),
        ]);

        $request = new EditorialRequestData(
            articleId: 7,
            language: 'en',
            contentType: 'blog',
            titleDirection: 'Test title',
            guideline: ['prohibited_claims' => ['No invention.']],
            sourceType: 'writer_original',
            sourceVersion: 2,
            sourceHash: str_repeat('a', 64),
            sourceNormalizedJson: ['schema' => 'google-docs-normalized-v1', 'content' => []],
        );

        $result = (new OpenAiEditorialClient)->edit($request);

        self::assertSame('openai', $result->provider);
        self::assertSame('gpt-test', $result->model);
        self::assertSame('Edited title', $result->title);
        self::assertCount(1, $result->blocks);
        self::assertCount(1, $result->verificationRequired);

        Http::assertSent(function ($httpRequest): bool {
            $payload = $httpRequest->data();

            return $httpRequest->url() === 'https://api.openai.test/v1/responses'
                && ($payload['model'] ?? null) === 'gpt-test'
                && ($payload['text']['format']['type'] ?? null) === 'json_schema'
                && ($payload['text']['format']['strict'] ?? null) === true
                && ($payload['text']['format']['schema']['additionalProperties'] ?? null) === false
                && str_contains((string) ($payload['instructions'] ?? ''), 'Never invent');
        });
    }

    public function test_available_is_false_without_api_key(): void
    {
        config()->set('services.content_ai.api_key', '');

        self::assertFalse((new OpenAiEditorialClient)->available());
    }
}
