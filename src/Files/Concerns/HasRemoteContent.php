<?php

namespace Laravel\Ai\Files\Concerns;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

trait HasRemoteContent
{
    protected ?Response $response = null;

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return $this->response()->body();
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return $this->name ?? basename(is_string($path) ? $path : '');
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime ?? ((new Stringable($this->response()->header('Content-Type')))->before(';')->trim()->toString() ?: null);
    }

    /**
     * Get the declared MIME type without fetching the remote resource.
     */
    public function declaredMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the HTTP response for the remote file.
     *
     * @throws InvalidArgumentException if the body exceeds the size limit.
     */
    protected function response(): Response
    {
        return $this->response ??= $this->fetch();
    }

    /**
     * Fetch the remote file, stopping once the body exceeds the size limit.
     *
     * @throws InvalidArgumentException if the body exceeds the size limit.
     */
    protected function fetch(): Response
    {
        $kilobytes = (int) config('ai.remote_files.max_size', 100 * 1024);
        $limit = $kilobytes * 1024;
        $body = Utils::streamFor();
        $tooLarge = false;

        try {
            return Http::withOptions([
                'on_headers' => function (ResponseInterface $response) use ($limit, &$tooLarge): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        $tooLarge = true;

                        throw new RuntimeException('The remote file declares a body larger than the size limit.');
                    }
                },
                'sink' => FnStream::decorate($body, [
                    'write' => function (string $chunk) use ($body, $limit, &$tooLarge): int {
                        if ($body->getSize() + strlen($chunk) > $limit) {
                            $tooLarge = true;

                            return 0;
                        }

                        return $body->write($chunk);
                    },
                ]),
            ])->get($this->url)->throw();
        } catch (HttpClientException $e) {
            throw $tooLarge ? new InvalidArgumentException("The remote file URL [{$this->url}] exceeds the maximum size of {$kilobytes} kilobytes.") : $e;
        }
    }

    public function __toString(): string
    {
        return $this->content();
    }
}
