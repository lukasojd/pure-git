<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\Infrastructure\Object;

use Lukasojd\PureGit\Infrastructure\Object\DeltaDecoder;
use Lukasojd\PureGit\Infrastructure\Object\DeltaEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeltaEncoderTest extends TestCase
{
    #[Test]
    public function roundtripIdenticalData(): void
    {
        $data = str_repeat("Hello World\n", 100);
        $delta = DeltaEncoder::encode($data, $data);

        if ($delta === null) {
            $this->markTestSkipped('Delta not beneficial for identical data');
        }

        $result = DeltaDecoder::apply($data, $delta);
        $this->assertSame($data, $result);
    }

    #[Test]
    public function roundtripSmallModification(): void
    {
        $base = str_repeat("line of text number one\n", 200);
        $target = str_repeat("line of text number one\n", 100)
            . "INSERTED LINE\n"
            . str_repeat("line of text number one\n", 100);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta, 'Delta should be produced for similar data');
        $this->assertLessThan(strlen($target), strlen($delta), 'Delta should be smaller than target');

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result);
    }

    #[Test]
    public function roundtripAppendedData(): void
    {
        $base = str_repeat("existing content here\n", 200);
        $target = $base . str_repeat("new appended content\n", 50);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta);

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result);
    }

    #[Test]
    public function roundtripPrependedData(): void
    {
        $base = str_repeat("existing content here\n", 200);
        $target = str_repeat("prepended data line\n", 50) . $base;

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta);

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result);
    }

    #[Test]
    public function returnsNullForCompletelyDifferentData(): void
    {
        $base = str_repeat('AAAA', 100);
        $target = str_repeat('BBBB', 100);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNull($delta, 'Delta should be null when not beneficial');
    }

    #[Test]
    public function returnsNullForSmallData(): void
    {
        $delta = DeltaEncoder::encode('short', 'other');
        $this->assertNull($delta, 'Delta should be null for data smaller than block size');
    }

    #[Test]
    public function roundtripLargeFile(): void
    {
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = sprintf("line %04d: %s\n", $i, str_repeat('x', 60));
        }
        $base = implode('', $lines);

        // Modify some lines in the middle
        $lines[100] = "MODIFIED LINE 100\n";
        $lines[200] = "MODIFIED LINE 200\n";
        $lines[300] = "MODIFIED LINE 300\n";
        $target = implode('', $lines);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta);
        $this->assertLessThan(strlen($target) / 2, strlen($delta), 'Delta should be significantly smaller');

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result);
    }

    #[Test]
    public function roundtripRealisticPhpFile(): void
    {
        $base = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

final class UserService
{
    public function __construct(
        private readonly UserRepository $repository,
    ) {
    }

    public function findById(int $id): ?User
    {
        return $this->repository->find($id);
    }

    public function create(string $name, string $email): User
    {
        $user = new User($name, $email);
        $this->repository->save($user);
        return $user;
    }
}

PHP;

        // Simulate adding a method
        $target = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

final class UserService
{
    public function __construct(
        private readonly UserRepository $repository,
        private readonly EventDispatcher $dispatcher,
    ) {
    }

    public function findById(int $id): ?User
    {
        return $this->repository->find($id);
    }

    public function create(string $name, string $email): User
    {
        $user = new User($name, $email);
        $this->repository->save($user);
        $this->dispatcher->dispatch(new UserCreated($user));
        return $user;
    }

    public function delete(int $id): void
    {
        $user = $this->findById($id);
        if ($user !== null) {
            $this->repository->remove($user);
            $this->dispatcher->dispatch(new UserDeleted($user));
        }
    }
}

PHP;

        // Repeat to make it large enough for delta to be useful
        $base = str_repeat($base, 10);
        $target = str_repeat($target, 10);

        $delta = DeltaEncoder::encode($base, $target);
        $this->assertNotNull($delta);

        $result = DeltaDecoder::apply($base, $delta);
        $this->assertSame($target, $result);
    }
}
