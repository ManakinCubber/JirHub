<?php

namespace App\Handler;

use App\Dashboard\Handler\DashboardHandler;
use App\Handler\SynchronizationCommand\AddValidationRequiredLabelCommand;
use App\Handler\SynchronizationCommand\DeleteValidationRequiredLabelCommand;
use App\Handler\SynchronizationCommand\SynchronizationCommandInterface;
use App\Handler\SynchronizationCommand\TransitionJiraIssueToInProgressCommand;
use App\Handler\SynchronizationCommand\TransitionJiraIssueToInReviewCommand;
use App\Handler\SynchronizationCommand\TransitionJiraIssueToToValidateCommand;
use App\Handler\SynchronizationCommand\UpdatePullRequestDescriptionCommand;
use App\Handler\SynchronizationCommand\UpdatePullRequestLabelsCommand;
use App\Handler\SynchronizationCommand\UpdatePullRequestTitleCommand;
use App\Model\JirHubTask;
use App\Repository\GitHub\PullRequestRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class SynchronizationHandler
{
    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var DeleteValidationRequiredLabelCommand */
    private $deleteValidationRequiredLabelCommand;

    /** @var AddValidationRequiredLabelCommand */
    private $addValidationRequiredLabelCommand;

    /** @var UpdatePullRequestDescriptionCommand */
    private $updatePullRequestDescriptionCommand;

    /** @var UpdatePullRequestLabelsCommand */
    private $updatePullRequestLabelsCommand;

    /** @var UpdatePullRequestTitleCommand */
    private $updatePullRequestTitleCommand;

    /** @var TransitionJiraIssueToInProgressCommand */
    private $transitionJiraIssueToInProgressCommand;

    /** @var TransitionJiraIssueToInReviewCommand */
    private $transitionJiraIssueToInReviewCommand;

    /** @var TransitionJiraIssueToToValidateCommand */
    private $transitionJiraIssueToToValidateCommand;

    public function __construct(
        CacheItemPoolInterface $cache,
        DeleteValidationRequiredLabelCommand $deleteValidationRequiredLabelCommand,
        AddValidationRequiredLabelCommand $addValidationRequiredLabelCommand,
        UpdatePullRequestLabelsCommand $updatePullRequestLabelsCommand,
        UpdatePullRequestDescriptionCommand $updatePullRequestDescriptionCommand,
        UpdatePullRequestTitleCommand $updatePullRequestTitleCommand,
        TransitionJiraIssueToInProgressCommand $transitionJiraIssueToInProgressCommand,
        TransitionJiraIssueToInReviewCommand $transitionJiraIssueToInReviewCommand,
        TransitionJiraIssueToToValidateCommand $transitionJiraIssueToToValidateCommand
    ) {
        $this->cache                                  = $cache;
        $this->deleteValidationRequiredLabelCommand   = $deleteValidationRequiredLabelCommand;
        $this->addValidationRequiredLabelCommand      = $addValidationRequiredLabelCommand;
        $this->updatePullRequestLabelsCommand         = $updatePullRequestLabelsCommand;
        $this->updatePullRequestDescriptionCommand    = $updatePullRequestDescriptionCommand;
        $this->updatePullRequestTitleCommand          = $updatePullRequestTitleCommand;
        $this->transitionJiraIssueToInProgressCommand = $transitionJiraIssueToInProgressCommand;
        $this->transitionJiraIssueToInReviewCommand   = $transitionJiraIssueToInReviewCommand;
        $this->transitionJiraIssueToToValidateCommand = $transitionJiraIssueToToValidateCommand;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function synchronize(JirHubTask $jirHubTask): void
    {
        $pullRequest = $jirHubTask->getGithubPullRequest();

        // TODO : Belongs in a pre-synchronization event listener
        $this->cache->deleteItem(DashboardHandler::CACHE_KEY);
        $this->cache->deleteItem(PullRequestRepository::DEFAULT_LIST);

        if ('master' === $pullRequest->getBaseRef() && 'dev' === $pullRequest->getHeadRef()) {
            return;
        }

        $tasks = [
            $this->deleteValidationRequiredLabelCommand,
            $this->addValidationRequiredLabelCommand,
            $this->updatePullRequestLabelsCommand,
            $this->updatePullRequestDescriptionCommand,
            $this->updatePullRequestTitleCommand,
            $this->transitionJiraIssueToInProgressCommand,
            $this->transitionJiraIssueToInReviewCommand,
            $this->transitionJiraIssueToToValidateCommand,
        ];

        /** @var SynchronizationCommandInterface $task */
        foreach ($tasks as $task) {
            $task->execute($jirHubTask);
        }
    }
}
