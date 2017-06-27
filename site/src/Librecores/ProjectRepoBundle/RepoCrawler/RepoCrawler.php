<?php

namespace Librecores\ProjectRepoBundle\RepoCrawler;

use Doctrine\Common\Persistence\ObjectManager;
use Librecores\ProjectRepoBundle\Entity\Commit;
use Librecores\ProjectRepoBundle\Entity\SourceRepo;
use Librecores\ProjectRepoBundle\Util\MarkupToHtmlConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener;


/**
 * Repository crawler base class
 *
 * Get contents from a source code repository.
 */
abstract class RepoCrawler
{
    /**
     * @var SourceRepo
     */
    protected $repo;

    /**
     * @var MarkupToHtmlConverter
     */
    protected $markupConverter;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OutputParserInterface
     */
    protected $outputParser;

    /**
     * @var SourceCrawlerInterface[]
     */
    protected $sourceCrawlers;

    /**
     * @var ObjectManager
     */
    protected $manager;

    /**
     * RepoCrawler constructor.
     * @param SourceRepo $repo
     * @param MarkupToHtmlConverter $markupConverter
     * @param LoggerInterface $logger
     * @param OutputParserInterface $outputParser
     * @param SourceCrawlerInterface[] $sourceCrawlers
     * @param ObjectManager $manager
     */
    public function __construct(SourceRepo $repo,
                                MarkupToHtmlConverter $markupConverter, LoggerInterface $logger,
                                OutputParserInterface $outputParser, array $sourceCrawlers,
                                ObjectManager $manager)
    {
        $this->repo = $repo;
        $this->markupConverter = $markupConverter;
        $this->logger = $logger;
        $this->outputParser = $outputParser;
        $this->manager = $manager;
        $this->sourceCrawlers = $sourceCrawlers;

        if (!$this->isValidRepoType()) {
            throw new \RuntimeException("Repository type is not supported by this crawler.");
        }
    }

    /**
     * Is the source repository in $repo processable by this crawler?
     *
     * @return boolean
     */
    abstract protected function isValidRepoType(): bool;

    /**
     * Get the license text of the repository as safe HTML
     *
     * Usually this license text is taken from the LICENSE or COPYING files.
     *
     * "Safe" HTML is stripped from all possibly malicious content, such as
     * script tags, etc.
     *
     * @return string|null the license text, or null if none was found
     */
    abstract public function getLicenseTextSafeHtml(): ?string;

    /**
     * Get the description of the repository as safe HTML
     *
     * Usually this is the content of the README file.
     *
     * "Safe" HTML is stripped from all possibly malicious content, such as
     * script tags, etc.
     *
     * @return string|null the repository description, or null if none was found
     */
    abstract public function getDescriptionSafeHtml(): ?string;

    /**
     * Get all commits in the repository since a specified commit ID or all if
     * not specified
     *
     * Implementations supporting extraction of commits are required to return
     * an array of `SourceCommit` objects or an empty array if otherwise. The
     * default behavior is to return an empty array.
     *
     * @param string|null $sinceId ID of commit after which the commits are to be
     *                              returned
     * @return array all commits in the repository
     */
    public function fetchCommits(string $sinceId = null) : array
    {
        // default operation does noting
        return [];
    }

    /**
     * Checks whether the given commit ID exists on the default tree of the
     * repository
     *
     * @param string $id ID of the commit to search
     * @return bool commit exists in the tree ?
     */
    public function commitExists(string $id): bool
    {
        return false;
    }

    /**
     * Update the project associated with the crawled repository with
     * information extracted from the repo
     *
     * @return bool operation successful?
     */
    public function updateProject()
    {
        $project = $this->repo->getProject();
        if ($project === null) {
            $this->logger->debug('No project associated with source ' .
                'repository ' . $this->repo->getId());
            return false;
        }

        if ($project->getDescriptionTextAutoUpdate()) {
            $project->setDescriptionText($this->getDescriptionSafeHtml());
        }
        if ($project->getLicenseTextAutoUpdate()) {
            $project->setLicenseText($this->getLicenseTextSafeHtml());
        }

        $this->logger->info('Fetching commits for the project ' . $project->getFqname());
        $commitRepository = $this->manager->getRepository(Commit::class);
        $lastCommit = $commitRepository->latest($this->repo);

        $commits = [];

        // determine if our latest commit exists and fetch new commits since
        // what we have on DB
        if ($lastCommit && $this->commitExists($lastCommit->getCommitId())) {
            $commits = $this->fetchCommits($lastCommit->getCommitId());
        } else {
            // there has been a history rewrite
            // we drop everything and persist all commits to the DB
            //XXX: Find a way to find the common ancestor and do partial rewrites
            $commitRepository->removeAll($this->repo);
            $this->repo->getCommits()->clear();
            $commits = $this->fetchCommits();
        }

        // XXX: This is probably wrong, we should abstract the execution instead of
        // the task of commit parsing
        foreach ($commits as $commit) {
            $this->manager->persist($commit);
        }

        $this->logger->info('Running source code crawlers for the project ' . $project->getFqname());
        $this->runCrawlers();

        $this->manager->persist($project);
        $this->manager->flush();

        return true;
    }

    /**
     * Update the source repository entity with information obtained through
     * the crawler
     */
    public function updateSourceRepo()
    {
        // the default implementation is empty
    }

    /**
     * Run configured source crawlers on the repository.
     *
     * Implementations supporting source crawlers should override this
     */
    public function runCrawlers()
    {
        // the default implementation is empty
    }
}
