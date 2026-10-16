<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files;
use Laravel\Ai\Stores;

beforeEach(function (): void {
    foreach (['openai', 'anthropic', 'openrouter', 'gemini'] as $provider) {
        config(["ai.providers.{$provider}.key" => 'test-key']);
    }

    config(['ai.providers.azure.key' => 'test-key', 'ai.providers.azure.url' => 'https://test-resource.openai.azure.com']);

    Http::fake();
});

dataset('unsafe ids', [
    'parent traversal' => '../../vector_stores/victim',
    'dot segment' => '..',
    'nested path' => 'abc/def',
    'query injection' => 'abc?force=true',
    'fragment' => 'abc#x',
    'encoded slash' => 'abc%2F..',
]);

test('file ids must be a single path segment', function (string $provider, string $operation, string $id): void {
    expect(fn () => Files::$operation($id, provider: $provider))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['openai', 'azure', 'anthropic', 'openrouter', 'gemini'])->with(['get', 'delete'])->with('unsafe ids');

test('store ids must be a single path segment', function (string $provider, string $operation, string $id): void {
    expect(fn () => Stores::$operation($id, provider: $provider))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['openai', 'azure', 'gemini'])->with(['get', 'delete'])->with('unsafe ids');

test('store document ids must be a single path segment', function (string $provider, string $id): void {
    $instance = app(AiManager::class)->instance($provider);

    expect(fn () => $instance->storeGateway()->removeFile($instance, 'store123', $id))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['openai', 'azure', 'gemini'])->with('unsafe ids');

test('gemini resource ids cannot name a different resource through their prefix', function (string $operation, string $id): void {
    expect(fn () => match ($operation) {
        'file' => Files::delete($id, provider: 'gemini'),
        'store' => Stores::delete($id, provider: 'gemini'),
        'document' => app(AiManager::class)->instance('gemini')->storeGateway()->removeFile(app(AiManager::class)->instance('gemini'), 'store123', $id),
    })->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'file pointing at a store' => ['file', 'files/../fileSearchStores/victim'],
    'store pointing at a document' => ['store', 'fileSearchStores/victim/documents/doc456'],
    'document pointing at a store' => ['document', 'fileSearchStores/victim'],
    'document in a different store' => ['document', 'fileSearchStores/other/documents/doc456'],
]);
