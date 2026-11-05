import { hasKey, t } from '../i18n';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields?: Record<string, string[]>;
  /** Seconds until a throttled request may be retried. */
  readonly retryAfter?: number;
  /** The open payment a 409 renewal_pending points at. */
  readonly paymentId?: number;
  /** When the open checkout stops being payable (ISO), on a 409 renewal_pending. */
  readonly checkoutExpiresAt?: string;
  /** Whole seconds left on that checkout, by the server's clock: the value to trust, not the phone's idea of the time. */
  readonly checkoutExpiresIn?: number;

  constructor(
    status: number,
    code: string,
    message: string,
    extra: { fields?: Record<string, string[]>; retryAfter?: number; paymentId?: number; checkoutExpiresAt?: string; checkoutExpiresIn?: number } = {},
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = extra.fields;
    this.retryAfter = extra.retryAfter;
    this.paymentId = extra.paymentId;
    this.checkoutExpiresAt = extra.checkoutExpiresAt;
    this.checkoutExpiresIn = extra.checkoutExpiresIn;
  }
}

/**
 * Message in the app's language for a known code. The API's own text is only a fallback for codes the app
 * has no string for, because the API does not read Accept-Language on these routes and answers in Turkish.
 */
export function messageFor(code: string, serverMessage?: string, retryAfter?: number): string {
  const key = `errors.${code}`;
  if (hasKey(key)) {
    return t(key, { minutes: Math.max(1, Math.ceil((retryAfter ?? 60) / 60)) });
  }
  return serverMessage || t('errors.unknown');
}

function statusFallbackCode(status: number): string {
  if (status === 401) return 'unauthenticated';
  if (status === 402) return 'subscription_expired';
  if (status === 403) return 'forbidden';
  if (status === 404) return 'not_found';
  if (status === 422) return 'validation_failed';
  if (status === 429) return 'too_many_requests';
  if (status >= 500) return 'server_error';
  return 'unknown';
}

/** Builds an ApiError from a non-2xx response body (already parsed, or undefined when it was not JSON). */
export function errorFromResponse(status: number, body: unknown, retryAfterHeader?: string | null): ApiError {
  const data = (body && typeof body === 'object' ? body : {}) as Record<string, unknown>;
  const bodyCode = typeof data.code === 'string' ? data.code : undefined;
  const code = status >= 500 ? 'server_error' : (bodyCode ?? statusFallbackCode(status));

  let fields: Record<string, string[]> | undefined;
  if (data.errors && typeof data.errors === 'object') {
    fields = {};
    for (const [name, messages] of Object.entries(data.errors as Record<string, unknown>)) {
      fields[name] = Array.isArray(messages) ? messages.map(String) : [String(messages)];
    }
  }

  const headerSeconds = retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : NaN;
  const bodySeconds = typeof data.retry_after === 'number' ? data.retry_after : NaN;
  const retryAfter = [bodySeconds, headerSeconds].find((n) => Number.isFinite(n));

  const serverMessage = typeof data.message === 'string' ? data.message : undefined;
  return new ApiError(status, code, messageFor(code, serverMessage, retryAfter), {
    fields,
    retryAfter,
    paymentId: typeof data.payment_id === 'number' ? data.payment_id : undefined,
    checkoutExpiresAt: typeof data.checkout_expires_at === 'string' ? data.checkout_expires_at : undefined,
    checkoutExpiresIn: typeof data.checkout_expires_in === 'number' ? data.checkout_expires_in : undefined,
  });
}

export function networkError(): ApiError {
  return new ApiError(0, 'network_error', messageFor('network_error'));
}

/** Whatever a screen caught, as an ApiError it can show (anything unexpected becomes the generic message). */
export function toApiError(error: unknown): ApiError {
  return error instanceof ApiError ? error : new ApiError(0, 'unknown', t('errors.unknown'));
}

/** First message for a field, for display under an input. */
export function fieldError(error: unknown, field: string): string | undefined {
  return error instanceof ApiError ? error.fields?.[field]?.[0] : undefined;
}
