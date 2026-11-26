const MIN = 60_000;
const HOUR = 60 * MIN;
const DAY = 24 * HOUR;
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function startOfDay(d: Date): number {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

function parse(iso: string | null | undefined): Date | null {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Conversation-list stamp: "now", "2m", "18m", "1h", then "Tue" within a week, then "Nov 21" (and the year if not this year). */
export function listStamp(iso: string | null | undefined, now: Date = new Date()): string {
  const d = parse(iso);
  if (!d) return '';
  const diff = now.getTime() - d.getTime();
  if (diff < MIN) return 'now';
  if (diff < HOUR) return `${Math.floor(diff / MIN)}m`;
  if (startOfDay(d) === startOfDay(now) || diff < 6 * HOUR) return `${Math.floor(diff / HOUR)}h`;
  const days = Math.round((startOfDay(now) - startOfDay(d)) / DAY);
  if (days < 7) return WEEKDAYS[d.getDay()];
  const md = `${MONTHS[d.getMonth()]} ${d.getDate()}`;
  return d.getFullYear() === now.getFullYear() ? md : `${md}, ${d.getFullYear()}`;
}

/** "4:12 PM". */
export function clockTime(iso: string | null | undefined): string {
  const d = parse(iso);
  if (!d) return '';
  const h = d.getHours();
  const m = String(d.getMinutes()).padStart(2, '0');
  return `${h % 12 === 0 ? 12 : h % 12}:${m} ${h < 12 ? 'AM' : 'PM'}`;
}

/** Day separator: "Today", "Yesterday", "Tuesday" within a week, else "Nov 21" / "Nov 21, 2025". */
export function dayLabel(iso: string | null | undefined, now: Date = new Date()): string {
  const d = parse(iso);
  if (!d) return '';
  const days = Math.round((startOfDay(now) - startOfDay(d)) / DAY);
  if (days === 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days > 1 && days < 7) return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][d.getDay()];
  const md = `${MONTHS[d.getMonth()]} ${d.getDate()}`;
  return d.getFullYear() === now.getFullYear() ? md : `${md}, ${d.getFullYear()}`;
}

/** Same calendar day (local time)? */
export function sameDay(a: string | null | undefined, b: string | null | undefined): boolean {
  const x = parse(a);
  const y = parse(b);
  return !!x && !!y && startOfDay(x) === startOfDay(y);
}
