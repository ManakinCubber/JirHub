<?php

namespace App\Controller;

use App\Exception\PullRequestNotFoundException;
use App\Exception\UnexpectedContentType;
use App\Handler\GitHubHandler;
use App\Handler\JirHubTaskHandler;
use App\Handler\SlackHandler;
use App\Handler\SynchronizationHandler;
use App\Repository\GitHub\Constant\PullRequestSearchFilters;
use App\Repository\GitHub\PullRequestRepository;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class IndexController extends AbstractController
{
    /**
     * @Route("/check", name="check_deployability", methods={"GET"})
     */
    public function checkAction(Request $request, GitHubHandler $gitHubHandler): Response
    {
        $branch = $request->get('branch');
        $env    = $request->get('env');

        return new JsonResponse(
            [
                'message' => $gitHubHandler->checkDeployability($branch, $env, null),
            ]
        );
    }

    /**
     * @Route("/apply", name="apply_labels", methods={"GET"})
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws UnexpectedContentType
     */
    public function applyAction(Request $request, GitHubHandler $gitHubHandler): Response
    {
        $branch = $request->get('branch');
        $env    = $request->get('env');

        return new Response((int) $gitHubHandler->applyLabels($branch, $env));
    }

    /**
     * @Route("/jira_webhook", name="jira_webhook", methods={"POST"})
     */
    public function jiraWebhookAction(Request $request, PullRequestRepository $pullRequestRepository): Response
    {
        $data   = json_decode($request->getContent(), true);
        $status = $data['issue']['fields']['status']['name'];
        $key    = $data['issue']['key'];

        if ($status === getenv('JIRA_STATUS_DONE')) {
            $pullRequest = array_pop(
                $pullRequestRepository->search(
                    [PullRequestSearchFilters::HEAD => $key]
                )
            );

            if (null === $pullRequest) {
                $pullRequest = array_pop(
                    $pullRequestRepository->search(
                        [PullRequestSearchFilters::TITLE => $key]
                    )
                );
            }

            if (null !== $pullRequest) {
                $pullRequestRepository->merge($pullRequest);
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Route("/github_webhook", name="github_webhook", methods={"POST"})
     *
     * @throws InvalidArgumentException
     * @throws UnexpectedContentType
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws PullRequestNotFoundException
     */
    public function githubWebhookAction(
        Request $request,
        JirHubTaskHandler $jirHubTaskHandler,
        SynchronizationHandler $synchronizationHandler
    ): Response {
        $data = json_decode($request->getContent(), true);

        $jirHubTask = $jirHubTaskHandler->getJirHubTaskFromGithubWebhookData($data);
        $synchronizationHandler->synchronize($jirHubTask);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Route("/slack_interactions_webhook", name="slack_interactions_webhook", methods={"POST"})
     */
    public function slackInteractionsWebhookAction(
        Request $request,
        SlackHandler $slackHandler
    ): Response {
        return new JsonResponse($slackHandler->handleInteraction(json_decode(str_replace('payload=', '', urldecode($request->getContent())), true)), Response::HTTP_OK);
    }
}
