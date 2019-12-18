<?php

namespace App\Repository\Jira;

use App\Client\JiraClient;
use App\Exception\UnexpectedContentType;
use App\Factory\JiraIssueFactory;
use App\Model\JiraIssue;
use App\Model\JiraTransition;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class JiraIssueRepository
{
    private const ROUTE_GET_ISSUE       = '/issue/%s';
    private const ROUTE_POST_TRANSITION = '/issue/%s/transitions';

    /** @var JiraClient */
    private $jiraClient;

    /** @var JiraIssueFactory */
    private $jiraIssueFactory;

    public function __construct(JiraClient $jiraClient, JiraIssueFactory $jiraIssueFactory)
    {
        $this->jiraClient       = $jiraClient;
        $this->jiraIssueFactory = $jiraIssueFactory;
    }

    /**
     * @throws UnexpectedContentType
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getIssue(string $issueKey): JiraIssue
    {
        $issueData = $this->jiraClient->get(
            sprintf(self::ROUTE_GET_ISSUE, $issueKey),
            ['expand' => 'transitions']
        );

        return $this->jiraIssueFactory->create($issueData);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws UnexpectedContentType
     */
    public function transitionIssueTo(string $issueKey, JiraTransition $jiraTransition): void
    {
        $this->jiraClient->post(
            sprintf(self::ROUTE_POST_TRANSITION, $issueKey),
            [],
            $jiraTransition->toArray()
        );
    }
}
