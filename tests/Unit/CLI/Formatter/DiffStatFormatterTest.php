<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Unit\CLI\Formatter;

use Lukasojd\PureGit\CLI\Formatter\DiffStatFormatter;
use Lukasojd\PureGit\Domain\Diff\DiffHunk;
use Lukasojd\PureGit\Domain\Diff\DiffLine;
use Lukasojd\PureGit\Domain\Diff\DiffLineType;
use Lukasojd\PureGit\Domain\Diff\FileDiff;
use Lukasojd\PureGit\Domain\Diff\FileStatus;
use PHPUnit\Framework\TestCase;

final class DiffStatFormatterTest extends TestCase
{
    public function testEmptyDiffsReturnsEmptyString(): void
    {
        self::assertSame('', DiffStatFormatter::format([]));
    }

    public function testSingleAddedFile(): void
    {
        $hunk = new DiffHunk(0, 0, 1, 3, [
            new DiffLine(DiffLineType::Added, 'line1', null, 1),
            new DiffLine(DiffLineType::Added, 'line2', null, 2),
            new DiffLine(DiffLineType::Added, 'line3', null, 3),
        ]);
        $diff = new FileDiff('newfile.txt', FileStatus::Added, [$hunk]);

        $output = $this->stripAnsi(DiffStatFormatter::format([$diff]));

        self::assertStringContainsString('newfile.txt', $output);
        self::assertStringContainsString('3 +++', $output);
        self::assertStringContainsString('1 file changed', $output);
        self::assertStringContainsString('3 insertions(+)', $output);
        self::assertStringContainsString('create mode 100644 newfile.txt', $output);
    }

    public function testSingleDeletedFile(): void
    {
        $hunk = new DiffHunk(1, 2, 0, 0, [
            new DiffLine(DiffLineType::Removed, 'old1', 1, null),
            new DiffLine(DiffLineType::Removed, 'old2', 2, null),
        ]);
        $diff = new FileDiff('removed.txt', FileStatus::Deleted, [$hunk]);

        $output = $this->stripAnsi(DiffStatFormatter::format([$diff]));

        self::assertStringContainsString('removed.txt', $output);
        self::assertStringContainsString('2 --', $output);
        self::assertStringContainsString('2 deletions(-)', $output);
        self::assertStringContainsString('delete mode 100644 removed.txt', $output);
    }

    public function testModifiedFileShowsPlusAndMinus(): void
    {
        $hunk = new DiffHunk(1, 3, 1, 4, [
            new DiffLine(DiffLineType::Context, 'unchanged', 1, 1),
            new DiffLine(DiffLineType::Removed, 'old', 2, null),
            new DiffLine(DiffLineType::Added, 'new1', null, 2),
            new DiffLine(DiffLineType::Added, 'new2', null, 3),
            new DiffLine(DiffLineType::Context, 'unchanged2', 3, 4),
        ]);
        $diff = new FileDiff('modified.php', FileStatus::Modified, [$hunk]);

        $output = $this->stripAnsi(DiffStatFormatter::format([$diff]));

        self::assertStringContainsString('modified.php', $output);
        self::assertStringContainsString('3 ++-', $output);
        self::assertStringContainsString('1 file changed', $output);
        self::assertStringContainsString('2 insertions(+)', $output);
        self::assertStringContainsString('1 deletion(-)', $output);
    }

    public function testMultipleFilesAlignsPaths(): void
    {
        $shortHunk = new DiffHunk(0, 0, 1, 1, [
            new DiffLine(DiffLineType::Added, 'x', null, 1),
        ]);
        $longHunk = new DiffHunk(1, 1, 1, 1, [
            new DiffLine(DiffLineType::Removed, 'a', 1, null),
            new DiffLine(DiffLineType::Added, 'b', null, 1),
            new DiffLine(DiffLineType::Added, 'c', null, 2),
        ]);

        $diffs = [
            new FileDiff('a.txt', FileStatus::Added, [$shortHunk]),
            new FileDiff('src/very/long/path/file.php', FileStatus::Modified, [$longHunk]),
        ];

        $output = $this->stripAnsi(DiffStatFormatter::format($diffs));

        self::assertStringContainsString('2 files changed', $output);
        self::assertStringContainsString('3 insertions(+)', $output);
        self::assertStringContainsString('1 deletion(-)', $output);
    }

    public function testSummaryUseSingularForSingleInsertion(): void
    {
        $hunk = new DiffHunk(0, 0, 1, 1, [
            new DiffLine(DiffLineType::Added, 'x', null, 1),
        ]);
        $diff = new FileDiff('f.txt', FileStatus::Added, [$hunk]);

        $output = $this->stripAnsi(DiffStatFormatter::format([$diff]));

        self::assertStringContainsString('1 insertion(+)', $output);
    }

    public function testOutputContainsAnsiColors(): void
    {
        $hunk = new DiffHunk(1, 1, 1, 1, [
            new DiffLine(DiffLineType::Added, 'new', null, 1),
            new DiffLine(DiffLineType::Removed, 'old', 1, null),
        ]);
        $diff = new FileDiff('file.txt', FileStatus::Modified, [$hunk]);

        $raw = DiffStatFormatter::format([$diff]);

        self::assertStringContainsString("\033[32m", $raw, 'Should contain green ANSI code');
        self::assertStringContainsString("\033[31m", $raw, 'Should contain red ANSI code');
        self::assertStringContainsString("\033[0m", $raw, 'Should contain reset ANSI code');
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
