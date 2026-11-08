<?php

namespace App\Domain\SourceReference\Actions;

use App\Domain\SourceReference\Models\SourceReference;

/**
 * Readers need to retain where they saved a story while reading content from elsewhere.
 * Keep the submitted URL as the source identity; content_url and the persisted plan
 * describe what to capture, without creating a second saved source or losing provenance.
 */
class ResolveReadingTarget
{
    public function handle(SourceReference $sourceReference): void
    {
        // Retries must not switch articles or repeat discovery calls after capture starts.
        // Persist one target so HTML and screenshot describe the same reading material.
        if ($sourceReference->content_url !== null) {
            return;
        }

        [$key, $profile] = $this->profileFor($sourceReference->url);
        $target = $profile['resolver'] === null
            ? ['content_url' => $sourceReference->url]
            : app($profile['resolver'])->handle($sourceReference->url);

        // A publisher may need its own extractor even when discovered through HN.
        // Only its flow overrides the plan: the saved origin still owns presentation.
        // Do not invoke another resolver here; unbounded link-following adds calls and
        // can drift away from the story the reader actually saved.
        [$targetKey, $targetProfile] = $this->profileFor($target['content_url']);
        $flow = $targetKey === 'default' ? $profile['flow'] : $targetProfile['flow'];

        $sourceReference->update([
            'content_url' => $target['content_url'],
            'metadata' => [
                ...($sourceReference->metadata ?? []),
                'reading' => [
                    ...($target['reading'] ?? []),
                    'profile' => $key,
                    'flow' => $flow,
                    'layout' => $profile['layout'],
                ],
                'resolution' => [
                    'status' => 'COMPLETED',
                    'html' => $target['html'] ?? null,
                    'error' => null,
                ],
            ],
        ]);
    }

    private function profileFor(string $url): array
    {
        $host = strtolower(rtrim(parse_url($url, PHP_URL_HOST) ?? '', '.'));
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach (config('reading.profiles') as $key => $profile) {
            if (in_array($host, $profile['hosts'], true)
                && (! isset($profile['path']) || $profile['path'] === $path)) {
                return [$key, $profile];
            }
        }

        return ['default', config('reading.profiles.default')];
    }
}
