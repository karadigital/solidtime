import { usePage } from '@inertiajs/vue3';

/**
 * Extracts the Plane work item identifier (f.e. "KARA-47") from a task name like "[KARA-47] Title".
 * Keep in sync with PlaneStatusService::workItemIdentifierFromTaskName().
 */
export function getPlaneIdentifier(taskName: string): string | null {
    const match = /^\[([A-Z][A-Z0-9]*-[0-9]+)\]/.exec(taskName);
    return match?.[1] ?? null;
}

export function isPlaneIntegrationEnabled(): boolean {
    const page = usePage<{ plane_integration_enabled?: boolean }>();
    return page.props.plane_integration_enabled === true;
}
