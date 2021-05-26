<?php

namespace App\Repository\GitHub;

use App\Client\GitHubClient;
use App\Event\PullRequestMergedEvent;
use App\Event\PullRequestMergeFailureEvent;
use App\Factory\PullRequestFactory;
use App\Model\Github\PullRequest;
use App\Repository\GitHub\Constant\PullRequestSearchFilters;
use App\Repository\GitHub\Constant\PullRequestUpdatableFields;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PullRequestRepository
{
    const DEFAULT_LIST = 'default_pull_request_list';

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var GitHubClient */
    private $client;

    /** @var string */
    private $repositoryOwner;

    /** @var string */
    private $repositoryName;

    /** @var PullRequestFactory */
    private $pullRequestFactory;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    public function __construct(
        CacheItemPoolInterface $cache,
        GitHubClient $client,
        string $repositoryOwner,
        string $repositoryName,
        PullRequestFactory $pullRequestFactory,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->cache              = $cache;
        $this->client             = $client;
        $this->repositoryOwner    = $repositoryOwner;
        $this->repositoryName     = $repositoryName;
        $this->pullRequestFactory = $pullRequestFactory;
        $this->eventDispatcher    = $eventDispatcher;
    }

    public function fetch($id): PullRequest
    {
        $pullRequestData = $this->client->pullRequests()->show(
            $this->repositoryOwner,
            $this->repositoryName,
            $id
        );

        return $this->pullRequestFactory->create($pullRequestData);
    }

    /**
     * @return PullRequest[]
     *
     * @throws InvalidArgumentException
     */
    public function search(array $parameters = []): array
    {
        $cacheDefaultList = false;
        $pullRequests     = [];

        if (
            false === \array_key_exists(PullRequestSearchFilters::RESULTS_PER_PAGE, $parameters)
            && false === \array_key_exists(PullRequestSearchFilters::STATE, $parameters)
            && false === \array_key_exists(PullRequestSearchFilters::BASE, $parameters)
            && false === \array_key_exists(PullRequestSearchFilters::HEAD, $parameters)
            && false === ($parameters[PullRequestSearchFilters::NO_CACHE] ?? false)
        ) {
            $cacheDefaultList = true;
        }

        $apiParameters = [
            PullRequestSearchFilters::RESULTS_PER_PAGE => 50,
        ];

        if (\array_key_exists(PullRequestSearchFilters::RESULTS_PER_PAGE, $parameters)) {
            $apiParameters[PullRequestSearchFilters::RESULTS_PER_PAGE] = $parameters[PullRequestSearchFilters::RESULTS_PER_PAGE];
            unset($parameters[PullRequestSearchFilters::RESULTS_PER_PAGE]);
        }

        if (\array_key_exists(PullRequestSearchFilters::STATE, $parameters)) {
            $apiParameters[PullRequestSearchFilters::STATE] = $parameters[PullRequestSearchFilters::STATE];
            unset($parameters[PullRequestSearchFilters::STATE]);
        }

        if (\array_key_exists(PullRequestSearchFilters::HEAD, $parameters)) {
            $apiParameters[PullRequestSearchFilters::HEAD] = $this->repositoryOwner . ':' . $parameters[PullRequestSearchFilters::HEAD];
            unset($parameters[PullRequestSearchFilters::HEAD]);
        }

        if (\array_key_exists(PullRequestSearchFilters::BASE, $parameters)) {
            $apiParameters[PullRequestSearchFilters::BASE] = $parameters[PullRequestSearchFilters::BASE];
            unset($parameters[PullRequestSearchFilters::BASE]);
        }

        if (true === $cacheDefaultList) {
            $cacheItem = $this->cache->getItem(self::DEFAULT_LIST);

            if (false === $cacheItem->isHit()) {
                $cacheItem->set(
                    $this->client->pullRequests()->all(
                        $this->repositoryOwner,
                        $this->repositoryName,
                        $apiParameters
                    )
                );

                $this->cache->save($cacheItem);
            }

            $pullRequestsData = $cacheItem->get();
        } else {
            $pullRequestsData = $this->client->pullRequests()->all(
                $this->repositoryOwner,
                $this->repositoryName,
                $apiParameters
            );
        }

        foreach ($pullRequestsData as $pullRequestData) {
            $pullRequests[] = $this->pullRequestFactory->create($pullRequestData);
        }

        foreach ($pullRequests as $key => $pullRequest) {
            if (\array_key_exists(PullRequestSearchFilters::TITLE, $parameters)
                && false === strpos($pullRequest->getTitle(), $parameters[PullRequestSearchFilters::TITLE])
            ) {
                unset($pullRequests[$key]);

                continue;
            }

            if (\array_key_exists(PullRequestSearchFilters::LABELS, $parameters)
                && false === empty(array_diff($parameters[PullRequestSearchFilters::LABELS], $pullRequest->getLabels()))
            ) {
                unset($pullRequests[$key]);

                continue;
            }
        }

        return $pullRequests;
    }

    public function update(PullRequest $pullRequest, array $data): PullRequest
    {
        $updateData = array_filter(
            $data,
            function ($key) {
                return true === \in_array($key, PullRequestUpdatableFields::getConstants());
            },
            ARRAY_FILTER_USE_KEY
        );

        $pullRequestData = $this->client->pullRequests()->update(
            $this->repositoryOwner,
            $this->repositoryName,
            $pullRequest->getId(),
            $updateData
        );

        return $this->pullRequestFactory->create($pullRequestData);
    }

    public function merge(PullRequest $pullRequest, $mergeMethod = 'squash'): void
    {
        try {
            $this->client->pullRequests()->merge(
                $this->repositoryOwner,
                $this->repositoryName,
                $pullRequest->getId(),
                'Merged by JirHub',
                $pullRequest->getHeadSha(),
                $mergeMethod,
                $pullRequest->getTitle()
            );
        } catch (\Exception $e) {
            $this->eventDispatcher->dispatch(new PullRequestMergeFailureEvent($pullRequest, $e->getMessage()));
        }

        $this->eventDispatcher->dispatch(new PullRequestMergedEvent($pullRequest));
    }
}
