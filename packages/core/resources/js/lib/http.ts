function xsrfHeader(): Record<string, string> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {};
}

/**
 * GET JSON from a same-origin endpoint outside an Inertia visit.
 */
export async function getJson<T>(
    url: string,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
}

/**
 * Upload one file as multipart form data to a same-origin endpoint, outside an Inertia visit.
 */
export async function uploadFile<T>(
    url: string,
    file: File,
    field = 'file',
): Promise<
    { ok: true; data: T } | { ok: false; errors: Record<string, string[]> }
> {
    const body = new FormData();
    body.append(field, file);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...xsrfHeader(),
        },
        body,
    });
    const json = await response.json().catch(() => ({}));

    return response.ok
        ? { ok: true, data: json as T }
        : {
              ok: false,
              errors: (json.errors ?? {
                  [field]: [
                      response.status === 413
                          ? 'That file is too large.'
                          : 'The upload failed. Try again.',
                  ],
              }) as Record<string, string[]>,
          };
}

/**
 * POST JSON to a same-origin endpoint with Laravel's CSRF cookie, outside an Inertia visit.
 */
export async function postJson<T>(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<
    { ok: true; data: T } | { ok: false; errors: Record<string, string[]> }
> {
    const response = await fetch(url, {
        method: 'POST',
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...xsrfHeader(),
        },
        body: JSON.stringify(body),
    });

    const json = await response.json();

    return response.ok
        ? { ok: true, data: json as T }
        : {
              ok: false,
              errors: (json.errors ?? {}) as Record<string, string[]>,
          };
}
