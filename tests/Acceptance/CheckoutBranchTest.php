<?php

declare(strict_types=1);

namespace Lukasojd\PureGit\Tests\Acceptance;

use Lukasojd\PureGit\Application\Handler\CheckoutHandler;
use Lukasojd\PureGit\Application\Handler\CheckoutResult;
use Lukasojd\PureGit\Domain\Object\Commit;
use Lukasojd\PureGit\Domain\Ref\RefName;
use PHPUnit\Framework\Attributes\Group;

#[Group('acceptance')]
final class CheckoutBranchTest extends AcceptanceTestCase
{
    /**
     * @var list<string>
     */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirs as $dir) {
            $this->cleanupDir($dir);
        }

        parent::tearDown();
    }

    public function testCheckoutNewBranchCommitAndVerify(): void
    {
        $url = $this->getHttpUrl();

        // Clone repo
        $repo = $this->cloneToTempDir($url);
        $this->cleanupDirs[] = $repo->workDir;

        // Create and switch to new branch
        $handler = new CheckoutHandler($repo);
        $result = $handler->checkoutNewBranch('feature-test-' . uniqid());

        self::assertSame(CheckoutResult::CreatedAndSwitched, $result);

        // Add a file and commit on the new branch
        $content = 'Feature branch content ' . uniqid();
        $this->addFileAndCommit($repo, 'feature-file.txt', $content, 'Add feature file');

        // Verify HEAD is on the new branch and the commit is there
        $headId = $repo->refs->resolve(RefName::head());
        $commit = $repo->objects->read($headId);
        self::assertInstanceOf(Commit::class, $commit);
        self::assertSame('Add feature file', $commit->message);

        // Verify file exists in working tree
        self::assertFileExists($repo->workDir . '/feature-file.txt');
        self::assertSame($content, file_get_contents($repo->workDir . '/feature-file.txt'));
    }
}
