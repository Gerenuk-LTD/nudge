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
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
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
