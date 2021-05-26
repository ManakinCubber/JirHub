<?php

namespace App\Model;

class ReviewEnvironment
{
    /** @var string */
    private $name;

    /** @var JirHubTask */
    private $jirHubTask;

    /** @var string */
    private $pullRequestTitle;

    private ?string $owner;

    public function __construct(string $name, ?string $owner = null)
    {
        $this->name  = $name;
        $this->owner = $owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getJirHubTask(): ?JirHubTask
    {
        return $this->jirHubTask;
    }

    public function setJirHubTask(JirHubTask $jirHubTask): self
    {
        $this->jirHubTask = $jirHubTask;

        return $this;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function getPullRequestTitle(): string
    {
        if (null === $this->pullRequestTitle) {
            $explodeTitle           = preg_split('/\[?ta-[0-9]{1,9}(\]|( ?(:|\|)))? ?/i', $this->jirHubTask->getGithubPullRequest()->getTitle());
            $this->pullRequestTitle = trim(array_pop($explodeTitle));
        }

        return $this->pullRequestTitle;
    }
}
