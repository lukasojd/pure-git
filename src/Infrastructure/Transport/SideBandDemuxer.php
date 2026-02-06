<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Infrastructure\Transport;

use Lukasojd\PureGit\Domain\Exception\PureGitException;

/**
 * Demultiplexes side-band-64k data from a Git upload-pack response.
 *
 * Side-band-64k format: pkt-line frames where first byte is channel:
 *   1 = pack data
 *   2 = progress (stderr)
 *   3 = fatal error
 *
 * HTTP response chunks don't align with pkt-line boundaries, so this
 * class maintains an internal buffer for partial pkt-lines.
 */
final class SideBandDemuxer
{
    private string $buffer = '';

    /**
     * @var list<string>
     */
    private array $packChunks = [];

    /**
     * Feed raw bytes from the HTTP response.
     *
     * @return string extracted pack data (may be empty if pkt-line is incomplete)
     */
    public function feed(string $data): string
    {
        $this->buffer .= $data;
        $this->packChunks = [];

        $this->processBuffer();

        if ($this->packChunks === []) {
            return '';
        }

        return implode('', $this->packChunks);
    }

    private function processBuffer(): void
    {
        while (strlen($this->buffer) >= 4) {
            $lenHex = substr($this->buffer, 0, 4);
            $len = intval($lenHex, 16);

            if ($len === 0) {
                // Flush packet — end of stream
                $this->buffer = substr($this->buffer, 4);

                return;
            }

            if ($len < 4) {
                throw new PureGitException(sprintf('Invalid pkt-line length in side-band: %d', $len));
            }

            // Wait for complete pkt-line
            if (strlen($this->buffer) < $len) {
                return;
            }

            $payload = substr($this->buffer, 4, $len - 4);
            $this->buffer = substr($this->buffer, $len);

            $this->dispatchChannel($payload);
        }
    }

    private function dispatchChannel(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        // NAK/ACK are protocol lines, not side-band framed — skip them
        if ($this->isProtocolLine($payload)) {
            return;
        }

        $channel = ord($payload[0]);
        $channelData = substr($payload, 1);

        match ($channel) {
            1 => $this->packChunks[] = $channelData,
            2 => $this->handleProgress($channelData),
            3 => throw new PureGitException('Remote error: ' . trim($channelData)),
            default => throw new PureGitException(sprintf('Unknown side-band channel: %d', $channel)),
        };
    }

    private function isProtocolLine(string $payload): bool
    {
        return str_starts_with($payload, 'NAK') || str_starts_with($payload, 'ACK');
    }

    private function handleProgress(string $message): void
    {
        // Progress messages go to stderr
        fwrite(STDERR, $message);
    }
}
