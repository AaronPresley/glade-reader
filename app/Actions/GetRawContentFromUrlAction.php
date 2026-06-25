<?php

namespace App\Actions;

use GuzzleHttp\Client;
use JsonException;
use RuntimeException;

class GetRawContentFromUrlAction
{
    private const ACTOR_ID = 'Z7nfd7WcpMxhdkNo6';

    private const POLL_INTERVAL_SECONDS = 2;

    private const MAX_WAIT_SECONDS = 300;

    private const TERMINAL_STATUSES = [
        'SUCCEEDED',
        'FAILED',
        'TIMED-OUT',
        'ABORTED',
    ];

    /**
     * @throws JsonException
     */
    public function handle(string $url): string
    {
        $apiToken = config('services.apify.api_token');

        if (! is_string($apiToken) || $apiToken === '') {
            throw new RuntimeException('Apify API token is not configured.');
        }

        $client = new Client([
            'base_uri' => 'https://api.apify.com',
            'headers' => [
                'Authorization' => 'Bearer '.$apiToken,
            ],
            'timeout' => 75,
        ]);

        $run = $this->startRun($client, $url);
        $runId = $run['id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            throw new RuntimeException('Apify actor run did not return a run ID.');
        }

        $completedRun = $this->waitForRunToFinish($client, $runId);
        $status = $completedRun['status'] ?? null;

        if ($status !== 'SUCCEEDED') {
            throw new RuntimeException('Apify actor run finished with status: '.($status ?? 'UNKNOWN'));
        }

        $datasetId = $completedRun['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new RuntimeException('Apify actor run did not return a default dataset ID.');
        }

        $response = $client->get('/v2/datasets/'.$datasetId.'/items', [
            'query' => [
                'format' => 'json',
                'clean' => false,
            ],
        ]);

        return $this->getFullHtmlFromDatasetItems((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function startRun(Client $client, string $url): array
    {
        $response = $client->post('/v2/actors/'.self::ACTOR_ID.'/runs', [
            'json' => [
                'requestListSources' => [
                    [
                        'url' => $url,
                    ],
                ],
                'proxyConfiguration' => [
                    'useApifyProxy' => true,
                ],
                'handlePageTimeoutSecs' => 60,
                'maxRequestRetries' => 1,
                'useChrome' => true,
            ],
        ]);

        return $this->decodeDataResponse((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function waitForRunToFinish(Client $client, string $runId): array
    {
        $startedAt = time();

        do {
            $run = $this->getRun($client, $runId);
            $status = $run['status'] ?? null;

            if (is_string($status) && in_array($status, self::TERMINAL_STATUSES, true)) {
                return $run;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while ((time() - $startedAt) < self::MAX_WAIT_SECONDS);

        throw new RuntimeException('Timed out waiting for Apify actor run to finish.');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function getRun(Client $client, string $runId): array
    {
        $response = $client->get('/v2/actor-runs/'.$runId);

        return $this->decodeDataResponse((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeDataResponse(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Apify response did not include a data object.');
        }

        return $data;
    }

    /**
     * @throws JsonException
     */
    private function getFullHtmlFromDatasetItems(string $body): string
    {
        $items = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $item = $items[0] ?? null;

        if (! is_array($item)) {
            throw new RuntimeException('Apify actor run did not return any dataset items.');
        }

        if (($item['#error'] ?? false) === true) {
            throw new RuntimeException('Apify actor returned an error for the requested URL.');
        }

        $fullHtml = $item['fullHtml'] ?? null;

        if (! is_string($fullHtml) || $fullHtml === '') {
            throw new RuntimeException('Apify actor did not return full HTML for the requested URL.');
        }

        return $fullHtml;
    }
}
