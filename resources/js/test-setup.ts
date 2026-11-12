// Vitest setup file. Wires up the globals that the production code reads
// off `window` (`getTimezoneSetting`, `getWeekStartSetting`, `getNumberFormat`,
// `getIntervalFormat`) so that helpers under test don't crash when imported
// outside the running app.

import { beforeEach, vi } from 'vitest';
import { Storage } from 'happy-dom';

declare global {
    interface Window {
        getTimezoneSetting: () => string;
        getWeekStartSetting: () => string;
        getNumberFormat: () => string;
        getIntervalFormat: () => string;
    }
}

window.getTimezoneSetting = vi.fn(() => 'UTC');
window.getWeekStartSetting = vi.fn(() => 'monday');
window.getNumberFormat = vi.fn(() => 'point');
window.getIntervalFormat = vi.fn(() => 'hours-minutes');

// happy-dom has no layout engine, so every element reports offsetWidth/offsetHeight of 0.
// TanStack Virtual (used by the project/task dropdown) measures via those properties, so
// without a size it renders zero rows. Give elements a usable box so virtualized components
// render their rows in component tests.
Object.defineProperty(HTMLElement.prototype, 'offsetWidth', { configurable: true, get: () => 400 });
Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
    configurable: true,
    get: () => 350,
});

// Node 22+ defines its own global localStorage, which is undefined without --localstorage-file and hides
// happy-dom's. Stores would then silently not persist anything, so tests would behave differently per Node version.
if (!globalThis.localStorage) {
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: new Storage(),
    });
}

// Stores persist state via useLocalStorage (e.g. the running timer). localStorage lives for the whole test file,
// so without this a test would start with the state the previous test left behind.
beforeEach(() => {
    localStorage.clear();
});
