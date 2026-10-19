<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\RemoteImage;

test('mime type falls back to the response content type', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/webp; charset=binary']),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBe('image/webp');
});

test('mime type is null when the response declares none', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200),
    ]);

    expect((new RemoteImage('https://example.com/photo'))->mimeType())->toBeNull();
});

test('declared mime type wins without fetching the url', function (): void {
    Http::fake();

    expect((new RemoteImage('https://example.com/photo', 'image/png'))->mimeType())->toBe('image/png');

    Http::assertNothingSent();
});

test('a failed fetch throws instead of inlining the error body', function (): void {
    Http::fake([
        'example.com/*' => Http::response('Not Found', 404),
    ]);

    (new RemoteImage('https://example.com/missing.png'))->content();
})->throws(RequestException::class);

test('the remote url is only fetched once', function (): void {
    Http::fake([
        'example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $image = new RemoteImage('https://example.com/photo.png');

    $image->mimeType();
    $image->content();

    Http::assertSentCount(1);
});

test('a body larger than the size limit is rejected', function (): void {
    config(['ai.remote_files.max_size' => 1]);

    Http::fake(function (Request $request, array $options) {
        if ($options['sink']->write(str_repeat('a', 1025)) === 0) {
            throw new ConnectionException('cURL error 23: Failure writing output to destination');
        }

        return Http::response('bytes');
    });

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 1 kilobytes');
});

test('a declared length larger than the size limit is rejected before the body is read', function (): void {
    config(['ai.remote_files.max_size' => 1]);

    $read = false;

    Http::fake(function (Request $request, array $options) use (&$read) {
        try {
            $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1025']));
        } catch (RuntimeException $e) {
            throw new ConnectionException('An error was encountered during the on_headers event', 0, $e);
        }

        $read = true;

        return Http::response('bytes');
    });

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 1 kilobytes')
        ->and($read)->toBeFalse();
});

test('the size limit defaults to 100 megabytes', function (): void {
    Http::fake(function (Request $request, array $options) {
        try {
            $options['on_headers'](new Psr7Response(200, ['Content-Length' => (string) (100 * 1024 * 1024 + 1)]));
        } catch (RuntimeException $e) {
            throw new ConnectionException('An error was encountered during the on_headers event', 0, $e);
        }

        return Http::response('bytes');
    });

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(InvalidArgumentException::class, 'exceeds the maximum size of 102400 kilobytes');
});

test('a body within the size limit is fetched', function (): void {
    config(['ai.remote_files.max_size' => 1]);

    Http::fake(function (Request $request, array $options) {
        $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1024']));

        if ($options['sink']->write(str_repeat('a', 1024)) === 0) {
            throw new ConnectionException('cURL error 23: Failure writing output to destination');
        }

        return Http::response('bytes');
    });

    expect((new RemoteImage('https://example.com/photo.png'))->content())->toBe('bytes');
});

test('a transport failure unrelated to the size limit is rethrown', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect(fn () => (new RemoteImage('https://example.com/photo.png'))->content())
        ->toThrow(ConnectionException::class, 'Failed to connect');
});
