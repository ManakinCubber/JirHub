<?php

namespace App\Handler;

use App\Event\LabelsAppliedEvent;
use App\Exception\PullRequestNotFoundException;
use App\Helper\JiraHelper;
use App\Model\Github\PullRequest;
use App\Model\Github\PullRequestReview;
use App\Repository\GitHub\Constant\PullRequestSearchFilters;
use App\Repository\GitHub\Constant\PullRequestSearchFilterState;
use App\Repository\GitHub\PullRequestLabelRepository;
use App\Repository\GitHub\PullRequestRepository;
use App\Repository\GitHub\PullRequestReviewRepository;
use App\Repository\Jira\JiraIssueRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class GitHubHandler
{
    const CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    const APPROVED          = 'APPROVED';

    /** @var PullRequestRepository */
    private $pullRequestRepository;

    /** @var PullRequestReviewRepository */
    private $pullRequestReviewRepository;

    /** @var PullRequestLabelRepository */
    private $pullRequestLabelRepository;

    /** @var JiraIssueRepository */
    private $jiraIssueRepository;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var array */
    private $labels;

    /** @var int */
    private $approveCount;

    /** @var string */
    private $defaultBaseBranch;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        PullRequestRepository $pullRequestRepository,
        PullRequestReviewRepository $pullRequestReviewRepository,
        PullRequestLabelRepository $pullRequestLabelRepository,
        JiraIssueRepository $jiraIssueRepository,
        EventDispatcherInterface $eventDispatcher,
        CacheItemPoolInterface $cache,
        array $labels,
        int $approveCount,
        string $defaultBaseBranch,
        LoggerInterface $logger
    ) {
        $this->pullRequestRepository       = $pullRequestRepository;
        $this->pullRequestReviewRepository = $pullRequestReviewRepository;
        $this->pullRequestLabelRepository  = $pullRequestLabelRepository;
        $this->jiraIssueRepository         = $jiraIssueRepository;
        $this->eventDispatcher             = $eventDispatcher;
        $this->cache                       = $cache;
        $this->labels                      = $labels;
        $this->approveCount                = $approveCount;
        $this->defaultBaseBranch           = $defaultBaseBranch;
        $this->logger                      = $logger;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getOpenPullRequestFromHeadBranch(string $headBranchName): ?PullRequest
    {
        $pullRequests = $this->pullRequestRepository->search(
            [PullRequestSearchFilters::HEAD => $headBranchName]
        );

        return array_pop($pullRequests);
    }

    public function isPullRequestApproved(PullRequest $pullRequest): bool
    {
        $approveCount = 0;

        if (null === $pullRequest->getReviews()) {
            $pullRequest->setReviews(array_reverse($this->pullRequestReviewRepository->search($pullRequest)));
        }

        /** @var PullRequestReview $review */
        foreach ($pullRequest->getReviews() as $review) {
            if (self::CHANGES_REQUESTED === $review->getState()) {
                return false;
            }

            if (self::APPROVED === $review->getState()) {
                ++$approveCount;

                if ($approveCount >= $this->approveCount) {
                    return true;
                }
            }
        }

        return false;
    }

    public function doesReviewBranchExists(string $reviewBranchName)
    {
        return \in_array(
            $this->labels['validation_prefix'] . $reviewBranchName,
            $this->labels['validation_environments'],
            true
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isReviewBranchAvailable(string $reviewBranchName, PullRequest $pullRequest)
    {
        $pullRequests = $this->pullRequestRepository->search(
            [
                PullRequestSearchFilters::LABELS => [
                    $this->labels['validation_prefix'] . $reviewBranchName,
                ],
            ]
        );

        $occupiedByTheSamePullRequest = (
            1 === \count($pullRequests)
            && array_pop($pullRequests)->getId() === $pullRequest->getId()
        );

        return 0 === \count($pullRequests)
            || $occupiedByTheSamePullRequest;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function checkDeployability(
        string $headBranchName,
        string $reviewBranchName,
        ?PullRequest $pullRequest = null
    ) {
        if ($headBranchName === $this->defaultBaseBranch) {
            return 'OK';
        }

        if (null === $pullRequest) {
            $pullRequest = $this->getOpenPullRequestFromHeadBranch($headBranchName);
        }

        if (null === $pullRequest) {
            return 'Pull Request not found.';
        }

        if ($pullRequest->hasLabel($this->labels['validation_prefix'] . $reviewBranchName)) {
            return 'OK';
        }

        if (empty($pullRequest) || null === $pullRequest) {
            return sprintf(
                'We have not found any pull request with head branch "%s".',
                $headBranchName
            );
        }

        if (!$this->doesReviewBranchExists($reviewBranchName)) {
            return 'The review branch "' . $reviewBranchName . '" does not exist or does not have any attributed label.';
        }

        if (!$this->isReviewBranchAvailable($reviewBranchName, $pullRequest)) {
            return 'The review branch "' . $reviewBranchName . '" is already used by another PR.';
        }

        if (!$this->isPullRequestApproved($pullRequest)) {
            return 'The pull request with head branch "' . $headBranchName . '" does not have enough approving reviews or has requested changes.';
        }

        return 'OK';
    }

    public function removeReviewLabels(PullRequest $pullRequest)
    {
        $reviewLabels   = $this->labels['validation_environments'];
        $reviewLabels[] = $this->labels['validation_required'];

        foreach ($reviewLabels as $reviewLabel) {
            if ($pullRequest->hasLabel($reviewLabel)) {
                $this->pullRequestLabelRepository->delete(
                    $pullRequest,
                    $reviewLabel
                );
            }
        }
    }

    public function isDeployed(PullRequest $pullRequest): bool
    {
        $reviewLabels = $this->labels['validation_environments'];

        foreach ($reviewLabels as $reviewLabel) {
            if ($pullRequest->hasLabel($reviewLabel)) {
                return true;
            }
        }

        return false;
    }

    public function isValidated(PullRequest $pullRequest): bool
    {
        return $pullRequest->hasLabel($this->labels['validated']);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function applyLabels(string $headBranchName, string $reviewBranchName): bool
    {
        $pullRequest = $this->getOpenPullRequestFromHeadBranch($headBranchName);

        if ('OK' !== $this->checkDeployability($headBranchName, $reviewBranchName, $pullRequest)) {
            return false;
        }

        $this->removeReviewLabels($pullRequest);
        $this->pullRequestLabelRepository->create(
            $pullRequest,
            $this->labels['validation_prefix'] . $reviewBranchName
        );

        $jiraIssueKey = JiraHelper::extractIssueKeyFromString($headBranchName)
            ?? JiraHelper::extractIssueKeyFromString($pullRequest->getTitle());

        $this->eventDispatcher->dispatch(new LabelsAppliedEvent($pullRequest, $reviewBranchName, $jiraIssueKey));

        return true;
    }

    /**
     * @throws InvalidArgumentException
     * @throws PullRequestNotFoundException
     */
    public function getPullRequestFromWebhookData(array $webhookData): PullRequest
    {
        $pullRequest = null;

        if (true === \array_key_exists('pull_request', $webhookData)) {
            $pullRequest = $this->pullRequestRepository->fetch($webhookData['pull_request']['number']);
        }

        if (true === \array_key_exists('ref', $webhookData)) {
            $pullRequests = array_pop(
                $this->pullRequestRepository->search(
                    [PullRequestSearchFilters::HEAD => $webhookData['ref']]
                )
            );
        }

        if (null === $pullRequest) {
            $this->logger->warning(
                sprintf(
                    'Could not find pull request from webhook data : %s', json_encode($webhookData)
                )
            );

            throw new PullRequestNotFoundException();
        }

        return $pullRequest;
    }
}
