<?php

namespace App\Actions;

use GuzzleHttp\Client;
use JsonException;
use RuntimeException;

class GetScreenshotFromUrlAction
{
    private const ACTOR_ID = 'YJCnS9qogi9XxDgLB';

    private const VIEWPORT_WIDTH = 1440;

    private const VIEWPORT_HEIGHT = 1000;

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

        $client = app(Client::class, ['config' => [
            'base_uri' => 'https://api.apify.com',
            'headers' => [
                'Authorization' => 'Bearer '.$apiToken,
            ],
            'timeout' => 75,
        ]]);

        $run = $this->startRun($client, $url);
        $runId = $run['id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            throw new RuntimeException('Apify screenshot actor run did not return a run ID.');
        }

        $completedRun = $this->waitForRunToFinish($client, $runId);
        $status = $completedRun['status'] ?? null;

        if ($status !== 'SUCCEEDED') {
            throw new RuntimeException('Apify screenshot actor run finished with status: '.($status ?? 'UNKNOWN'));
        }

        $datasetId = $completedRun['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new RuntimeException('Apify screenshot actor run did not return a default dataset ID.');
        }

        $storeId = $completedRun['defaultKeyValueStoreId'] ?? null;

        if (! is_string($storeId) || $storeId === '') {
            throw new RuntimeException('Apify screenshot actor run did not return a default key-value store ID.');
        }

        $response = $client->get('/v2/datasets/'.$datasetId.'/items', [
            'query' => [
                'format' => 'json',
                'clean' => false,
            ],
        ]);

        return $this->getScreenshotFromDatasetItems($client, $storeId, (string) $response->getBody());
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
                'startUrls' => [
                    [
                        'url' => $url,
                    ],
                ],
                'globs' => [],
                'pseudoUrls' => [],
                'excludes' => [],
                'linkSelector' => '',
                'keepUrlFragments' => false,
                'respectRobotsTxtFile' => false,
                'pageFunction' => sprintf(<<<'JS'
async function pageFunction(context) {
    const { page, request, log } = context;
    const title = await page.title();
    log.info(`URL: ${request.url} TITLE: ${title}`);

    await page.setViewport({
        width: %d,
        height: %d,
        deviceScaleFactor: 1
    });
    await new Promise((resolve) => setTimeout(resolve, 500));

    const screenshot = await page.screenshot({
        type: 'jpeg',
        quality: 85,
        fullPage: true
    });
    const screenshotKey = 'screenshot.jpg';
    await context.setValue(screenshotKey, Buffer.from(screenshot), { contentType: 'image/jpeg' });

    return {
        url: request.url,
        title,
        screenshotKey
    };
}
JS, self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT),
                'proxyConfiguration' => [
                    'useApifyProxy' => true,
                    'apifyProxyGroups' => [
                        'RESIDENTIAL',
                    ],
                ],
                'proxyRotation' => 'RECOMMENDED',
                'initialCookies' => [],
                'useChrome' => true,
                'headless' => true,
                'ignoreSslErrors' => false,
                'ignoreCorsAndCsp' => false,
                'downloadMedia' => true,
                'downloadCss' => true,
                'maxRequestRetries' => 1,
                'maxPagesPerCrawl' => 1,
                'maxResultsPerCrawl' => 1,
                'maxCrawlingDepth' => 0,
                'maxConcurrency' => 1,
                'pageLoadTimeoutSecs' => 60,
                'pageFunctionTimeoutSecs' => 60,
                'waitUntil' => [
                    'networkidle2',
                ],
                'preNavigationHooks' => <<<'JS'
[
    async (crawlingContext, gotoOptions) => {
        const { page } = crawlingContext;
        // ...
    },
]
JS,
                'postNavigationHooks' => <<<'JS'
[
    async (crawlingContext) => {
        const { page } = crawlingContext;
        // ...
    },
]
JS,
                'closeCookieModals' => false,
                'maxScrollHeightPixels' => 5000,
                'debugLog' => false,
                'browserLog' => false,
                'customData' => (object) [],
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

        throw new RuntimeException('Timed out waiting for Apify screenshot actor run to finish.');
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
    private function getScreenshotFromDatasetItems(Client $client, string $storeId, string $body): string
    {
        $items = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $item = $items[0] ?? null;

        if (! is_array($item)) {
            throw new RuntimeException('Apify screenshot actor run did not return any dataset items.');
        }

        if (($item['#error'] ?? false) === true) {
            throw new RuntimeException('Apify screenshot actor returned an error for the requested URL.');
        }

        $screenshotKey = $item['screenshotKey'] ?? null;

        if (! is_string($screenshotKey) || $screenshotKey === '') {
            throw new RuntimeException('Apify screenshot actor did not return a screenshot key.');
        }

        $response = $client->get('/v2/key-value-stores/'.rawurlencode($storeId).'/records/'.rawurlencode($screenshotKey));
        $screenshot = (string) $response->getBody();

        if ($screenshot === '' || $response->getHeaderLine('Content-Type') !== 'image/jpeg') {
            throw new RuntimeException('Apify screenshot actor returned invalid screenshot content.');
        }

        return $screenshot;
    }
}
