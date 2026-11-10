<?php

declare(strict_types=1);

namespace App\Service\Plane;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for the Plane public API (v1), authenticated with an API key.
 */
class PlaneClient
{
    private const int TIMEOUT_SECONDS = 5;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '' && $this->workspaceSlug() !== '';
    }

    /**
     * The API key acts on the whole Plane workspace, so only the configured solidtime organization may use it.
     */
    public function isEnabledForOrganization(string $organizationId): bool
    {
        $configuredOrganizationId = trim((string) config('services.plane.organization_id'));

        return $this->isConfigured() && $configuredOrganizationId !== '' && $configuredOrganizationId === $organizationId;
    }

    /**
     * @return array{id: string, project: string, state: string}|null null if the work item does not exist or is not
     *                                                                accessible (Plane answers 403 for an unknown project
     *                                                                identifier or a project the API user is no member of)
     *
     * @throws PlaneApiException
     */
    public function findWorkItem(string $identifier): ?array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->workspaceUrl('/work-items/'.$identifier.'/')));
        if ($response->status() === 404 || $response->status() === 403) {
            return null;
        }
        $this->ensureSuccessful($response);

        return [
            'id' => (string) $response->json('id'),
            'project' => (string) $response->json('project'),
            'state' => (string) $response->json('state'),
        ];
    }

    /**
     * @return list<array{id: string, name: string, group: string}>
     *
     * @throws PlaneApiException
     */
    public function listStates(string $projectId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->workspaceUrl('/projects/'.$projectId.'/states/')));
        $this->ensureSuccessful($response);

        /** @var array<int, array<string, mixed>> $results */
        $results = $response->json('results', []);
        $states = [];
        foreach ($results as $state) {
            $states[] = [
                'id' => (string) ($state['id'] ?? ''),
                'name' => (string) ($state['name'] ?? ''),
                'group' => (string) ($state['group'] ?? ''),
            ];
        }

        return $states;
    }

    /**
     * @throws PlaneApiException
     */
    public function updateWorkItemState(string $projectId, string $workItemId, string $stateId): void
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->patch(
            $this->workspaceUrl('/projects/'.$projectId.'/work-items/'.$workItemId.'/'),
            ['state' => $stateId]
        ));
        $this->ensureSuccessful($response);
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws PlaneApiException
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback(
                Http::withHeaders(['X-Api-Key' => $this->apiKey()])
                    ->acceptJson()
                    ->asJson()
                    ->timeout(self::TIMEOUT_SECONDS)
            );
        } catch (ConnectionException $exception) {
            throw new PlaneApiException('Plane could not be reached: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws PlaneApiException
     */
    private function ensureSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            throw new PlaneApiException('Plane API request failed with status '.$response->status());
        }
    }

    private function workspaceUrl(string $path): string
    {
        return $this->baseUrl().'/api/v1/workspaces/'.$this->workspaceSlug().$path;
    }

    private function apiKey(): string
    {
        return trim((string) config('services.plane.api_key'));
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.plane.base_url')), '/');
    }

    private function workspaceSlug(): string
    {
        return trim((string) config('services.plane.workspace_slug'));
    }
}
