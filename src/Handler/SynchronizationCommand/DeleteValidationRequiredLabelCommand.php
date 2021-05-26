<?php

namespace App\Handler\SynchronizationCommand;

use App\Handler\GitHubHandler;
use App\Model\JirHubTask;
use App\Repository\GitHub\PullRequestLabelRepository;
use Psr\Log\LoggerInterface;

final class DeleteValidationRequiredLabelCommand implements SynchronizationCommandInterface
{
    /** @var GitHubHandler */
    private $githubHandler;

    /** @var string */
    private $label;

    /** @var PullRequestLabelRepository */
    private $pullRequestLabelRepository;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        GitHubHandler $githubHandler,
        string $label,
        PullRequestLabelRepository $pullRequestLabelRepository,
        LoggerInterface $logger
    ) {
        $this->githubHandler              = $githubHandler;
        $this->label                      = $label;
        $this->pullRequestLabelRepository = $pullRequestLabelRepository;
        $this->logger                     = $logger;
    }

    public function execute(JirHubTask $jirHubTask): void
    {
        $pullRequest = $jirHubTask->getGithubPullRequest();

        if ($pullRequest->hasLabel($this->label)
            && (
                false === $this->githubHandler->isPullRequestApproved($pullRequest)
                || $this->githubHandler->isDeployed($pullRequest)
                || $this->githubHandler->isValidated($pullRequest)
                || $pullRequest->isInProgress()
            )
        ) {
            $this->pullRequestLabelRepository->delete(
                $pullRequest,
                $this->label
            );

            $this->logger->info(
                sprintf(
                    'Removed label %s from pull request #%d',
                    $this->label,
                    $pullRequest->getId()
                )
            );
        }
    }
}
