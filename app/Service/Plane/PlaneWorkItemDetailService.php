<?php

declare(strict_types=1);

namespace App\Service\Plane;

use Illuminate\Support\Facades\Cache;

/**
 * Read-only view of a Plane work item for the task detail panel.
 */
class PlaneWorkItemDetailService
{
    /**
     * Plane allows 60 requests per minute per API key, so details are cached briefly.
     */
    private const int CACHE_SECONDS = 60;

    public function __construct(
        private readonly PlaneClient $planeClient,
        private readonly PlaneDescriptionSanitizer $sanitizer,
    ) {}

    /**
     * @return array{
     *     identifier: string,
     *     name: string,
     *     url: string,
     *     state: array{name: string, color: string, group: string}|null,
     *     priority: string,
     *     assignees: list<array{display_name: string}>,
     *     labels: list<array{name: string, color: string}>,
     *     start_date: string|null,
     *     target_date: string|null,
     *     description_html: string
     * }|null null if the work item does not exist or is not accessible
     *
     * @throws PlaneApiException
     */
    public function detail(string $identifier): ?array
    {
        $cacheKey = 'plane:work-item:'.$identifier;
        /** @var array{found: bool, detail?: array<string, mixed>}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            /** @phpstan-ignore return.type (cached value written below) */
            return $cached['found'] ? ($cached['detail'] ?? null) : null;
        }

        $workItem = $this->planeClient->getWorkItem($identifier);
        $detail = $workItem !== null ? $this->map($identifier, $workItem) : null;
        Cache::put($cacheKey, $detail !== null ? ['found' => true, 'detail' => $detail] : ['found' => false], self::CACHE_SECONDS);

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array{
     *     identifier: string,
     *     name: string,
     *     url: string,
     *     state: array{name: string, color: string, group: string}|null,
     *     priority: string,
     *     assignees: list<array{display_name: string}>,
     *     labels: list<array{name: string, color: string}>,
     *     start_date: string|null,
     *     target_date: string|null,
     *     description_html: string
     * }
     */
    private function map(string $identifier, array $workItem): array
    {
        $state = $workItem['state'] ?? null;

        $assignees = [];
        foreach ($this->listOfArrays($workItem['assignees'] ?? null) as $assignee) {
            // Only the display name leaves the server, never emails or ids.
            $assignees[] = ['display_name' => $this->string($assignee['display_name'] ?? null)];
        }

        $labels = [];
        foreach ($this->listOfArrays($workItem['labels'] ?? null) as $label) {
            $labels[] = ['name' => $this->string($label['name'] ?? null), 'color' => $this->string($label['color'] ?? null)];
        }

        return [
            'identifier' => $identifier,
            'name' => $this->string($workItem['name'] ?? null),
            'url' => $this->planeClient->workItemUrl($identifier),
            'state' => is_array($state) ? [
                'name' => $this->string($state['name'] ?? null),
                'color' => $this->string($state['color'] ?? null),
                'group' => $this->string($state['group'] ?? null),
            ] : null,
            'priority' => $this->string($workItem['priority'] ?? null, 'none'),
            'assignees' => $assignees,
            'labels' => $labels,
            'start_date' => $this->nullableString($workItem['start_date'] ?? null),
            'target_date' => $this->nullableString($workItem['target_date'] ?? null),
            'description_html' => $this->sanitizer->sanitize($this->nullableString($workItem['description_html'] ?? null)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($value, 'is_array'));

        return $items;
    }

    private function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
