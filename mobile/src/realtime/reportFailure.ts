/** Stable prefix so a realtime failure is easy to find in Metro, LogBox or `adb logcat`. */
export const REALTIME_LOG_PREFIX = '[realtime]';

/** Which step of realtime setup failed. */
export type RealtimeStage = 'config' | 'transport' | 'subscribe';

/**
 * Reports that realtime setup failed. The caller still falls back to pull-to-refresh; this only makes the failure visible
 * (a silent catch once hid a crash that disabled live updates on every device — os-creator1/os-ai#725).
 *
 * - In development, warns with the full error (LogBox toast + Metro log, stack included).
 * - In release builds there is no crash/diagnostics SDK in the app, so it writes one short line to the native log
 *   (`adb logcat` / Xcode console): the stage plus the error's name and message only — no stack, config, keys, tokens,
 *   user or Business identifiers.
 */
export function reportRealtimeFailure(stage: RealtimeStage, error: unknown): void {
  if (__DEV__) {
    console.warn(`${REALTIME_LOG_PREFIX} ${stage} failed; falling back to pull-to-refresh.`, error);
    return;
  }
  console.warn(`${REALTIME_LOG_PREFIX} ${stage} failed: ${summarize(error)}`);
}

function summarize(error: unknown): string {
  const text = error instanceof Error ? `${error.name}: ${error.message}` : typeof error === 'string' ? error : 'unknown error';
  return text.length > 200 ? `${text.slice(0, 200)}…` : text;
}
