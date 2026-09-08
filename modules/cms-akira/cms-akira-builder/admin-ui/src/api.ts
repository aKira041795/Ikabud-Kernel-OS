import type { Boot } from './types';

export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Reads the authorized JSON bootstrap injected by the authenticated shell page. */
export function readBootstrap(): Boot {
  const el = document.getElementById('cms-akira-builder-bootstrap');
  if (el) {
    try {
      const parsed = JSON.parse(el.textContent ?? '{}') as Partial<Boot>;
      return {
        mode: parsed.mode === 'edit' ? 'edit' : 'list',
        apiBase: parsed.apiBase ?? '/api/v1/cms-akira/builder',
        entity_key: parsed.entity_key,
        posts: Array.isArray(parsed.posts) ? parsed.posts : [],
      };
    } catch {
      // fall through to a safe default
    }
  }
  return { mode: 'list', apiBase: '/api/v1/cms-akira/builder', posts: [] };
}

/** Creates a client-side idempotency key for governed builder mutations. */
export function newIdempotencyKey(): string {
  const rand =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  return `akira-admin-${rand}`;
}

/** Generic JSON transport for the authenticated capability bridge. Fail closed. */
export async function api<T>(apiBase: string, path: string, init: RequestInit = {}, includeKey = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const body = init.body;
  const isBody = typeof body === 'string' && body.length > 0;
  if (isBody) {
    headers['Content-Type'] = 'application/json';
  }
  let finalInit: RequestInit = { ...init, headers, credentials: 'include' };
  if (includeKey && isBody) {
    // For entity mutations the payload already carries idempotency_key (set by
    // the caller); this is a no-op helper retained for symmetric signatures.
    finalInit = finalInit;
  }
  const response = await fetch(`${apiBase}${path}`, finalInit);
  const payload = (await response.json().catch(() => null)) as Record<string, unknown> | null;
  if (!response.ok || !payload || payload.ok !== true) {
    const message = (payload && typeof payload.error === 'string' ? payload.error : `Request failed (${response.status})`) as string;
    throw new ApiError(response.status, message);
  }
  return payload as unknown as T;
}
