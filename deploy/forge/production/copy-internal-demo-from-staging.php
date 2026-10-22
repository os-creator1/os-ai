<?php

/**
 * One-time, idempotent copy of the INTERNAL demo Business setup from the staging database to production.
 *
 * Run by a human, over SSH, as the site user, from the PRODUCTION release directory:
 *
 *   cd /home/forge/getmotiongrove.com/current
 *   php deploy/forge/production/copy-internal-demo-from-staging.php --staging-env=/home/forge/<staging-site>/.env             # DRY RUN
 *   php deploy/forge/production/copy-internal-demo-from-staging.php --staging-env=/home/forge/<staging-site>/.env --apply     # writes
 *
 * What it does (every write goes through the application's own services/commands; nothing is a raw table copy):
 *   1. READS staging through a second, READ-ONLY connection (credentials come from the staging .env file and are never
 *      printed): the platform administrator email, the customer who owns "MotionGrove Internal", that Business, its
 *      Location, the demo Booking Type, its hosts, the host's weekly hours and future time off.
 *   2. Checks production: a Platform Owner must already exist (run install-production.sh first; this script never
 *      creates administrators).
 *   3. Creates the internal owner's login on production through CustomerRepository::store (same email/name/phone/
 *      timezone as staging). No password hash, session, token or API key is copied. The password is typed at a hidden
 *      prompt; without a terminal a random one is set and never shown (use "Forgot password").
 *   4. Runs the existing `demo-booking:provision` (find-or-create: Workspace, Business, Location, Booking Type, host,
 *      complimentary plan). A re-run reuses what exists.
 *   5. Copies the non-secret SETTINGS columns of the Business, Location and Booking Type (buffers, notice, booking
 *      window, slot interval, notifications, ...) for columns that exist in both databases. Columns that are keys,
 *      identifiers, connections, tokens or secrets are never copied.
 *   6. Sets the host's weekly hours with StaffAvailabilityService::replaceWeek (only when production has none yet,
 *      unless --replace-availability) and adds future time off that is not there yet.
 *   7. Copies the PUBLISHED /book-demo form (questions, order, options) through DemoFormRepository save+publish, unless
 *      production already has an identical published form.
 *   8. Points DEMO_BOOKING_TYPE_UUID at the real production Booking Type (one line of the production .env, atomic
 *      replace) and runs config:cache.
 *   9. Verifies: the records link up, the Business is active, the host is eligible, real slots exist in the next 21
 *      days, and https://<app url>/book-demo answers 200.
 *
 * Not copied, ever: users other than the two named, passwords, tokens, appointments, contacts, payments,
 * subscriptions, Stripe or any provider credential, other Workspaces/Businesses.
 * Staging is only read (SET SESSION TRANSACTION READ ONLY). Dry-run (the default) writes nothing anywhere.
 *
 * Options:
 *   --staging-env=PATH           required. The staging site's .env file (read only, values never printed).
 *   --apply                      actually write. Without it, only report.
 *   --confirm-database=NAME      required with --apply; must equal the connected production database name.
 *   --prod-env=PATH              production .env to edit (default: the .env of this release, symlink-resolved).
 *   --replace-availability       replace production weekly hours even if some exist.
 *   --allow-non-production       testing only: permit APP_ENV other than production.
 */

declare(strict_types=1);

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BookingType;
use App\Models\Customer;
use App\Models\DemoFormVersion;
use App\Models\StaffAvailabilityRule;
use App\Models\User;
use App\Models\Workspace;
use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\PublicSlotFinder;
use App\Library\Calendar\StaffAvailabilityService;
use App\Repositories\Contracts\CustomerRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = getcwd();
if (! is_file($root.'/artisan') || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run this from the application directory (the one containing 'artisan').\n");
    exit(2);
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}
$apply = isset($opts['apply']);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ---------------------------------------------------------------- helpers
$say = static function (string $line = ''): void { fwrite(STDOUT, $line."\n"); };
$fail = static function (string $why) use ($say): void { $say("STOP: {$why}"); exit(1); };
$short = static fn (mixed $v): string => $v === null ? 'NULL' : Str::limit(is_scalar($v) ? (string) $v : json_encode($v), 70, '…');

// A column is never copied if it could identify a row, point at another row, or carry a secret.
$neverCopy = static function (string $column): bool {
    return in_array($column, ['id', 'uid', 'status', 'activated_at', 'created_at', 'updated_at', 'deleted_at', 'archived_at', 'slug', 'subdomain', 'domain', 'custom_domain', 'canonical_domain', 'website_url'], true)
        || (bool) preg_match('/(^|_)(id|uid|uuid)$/', $column)
        || (bool) preg_match('/(token|secret|password|key|stripe|credential|api|oauth|webhook|zoom|google|meta|connection|encrypted|hash)/i', $column);
};

$parseEnv = static function (string $path): array {
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (($v[0] ?? '') === '"' || ($v[0] ?? '') === "'") {
            $v = substr($v, 1, (int) strrpos($v, $v[0]) - 1);
        } else {
            $v = trim(preg_replace('/\s+#.*$/', '', $v));
        }
        $out[trim($k)] = $v;
    }

    return $out;
};

$promptHidden = static function (string $label): ?string {
    if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
        return null;
    }
    fwrite(STDERR, $label);
    system('stty -echo');
    $v = rtrim((string) fgets(STDIN), "\r\n");
    system('stty echo');
    fwrite(STDERR, "\n");

    return $v;
};

// ---------------------------------------------------------------- guards
$prodDb = (string) DB::connection()->getDatabaseName();
if (! app()->environment('production') && ! isset($opts['allow-non-production'])) {
    $fail('APP_ENV is not production. This script is for the production site (use --allow-non-production only in a test).');
}
if ($apply && ($opts['confirm-database'] ?? null) !== $prodDb) {
    $fail("--apply needs --confirm-database={$prodDb} (the connected database).");
}
if (empty($opts['staging-env']) || ! is_file((string) $opts['staging-env'])) {
    $fail('--staging-env=<path to the staging .env> is required (it is only read; values are never printed).');
}

$stgEnv = $parseEnv((string) $opts['staging-env']);
foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $needed) {
    if (! isset($stgEnv[$needed])) {
        $fail("The staging .env has no {$needed}.");
    }
}
if ($stgEnv['DB_DATABASE'] === $prodDb) {
    $fail('Staging and production databases have the same name. Refusing.');
}

$base = config('database.connections.'.config('database.default'));
config(['database.connections.staging_src' => array_merge($base, [
    'host' => $stgEnv['DB_HOST'] ?? $base['host'],
    'port' => $stgEnv['DB_PORT'] ?? $base['port'],
    'database' => $stgEnv['DB_DATABASE'],
    'username' => $stgEnv['DB_USERNAME'],
    'password' => $stgEnv['DB_PASSWORD'],
])]);
$stg = DB::connection('staging_src');
$stg->statement('SET SESSION TRANSACTION READ ONLY');
$stg->getPdo();

$say($apply ? '=== APPLY (writes to production) ===' : '=== DRY RUN (nothing is written) ===');
$say("production database : {$prodDb}");
$say("staging database    : {$stgEnv['DB_DATABASE']} (read-only)");

// ---------------------------------------------------------------- 1. read staging
$ws = $stg->table('workspaces')->where('name', 'MotionGrove Internal')->orderBy('id')->first()
    ?? $fail('Staging has no Workspace named "MotionGrove Internal".');
$srcOwner = $stg->table('users')->where('id', $ws->owner_user_id)->first() ?? $fail('Staging owner user not found.');
$srcCustomer = $stg->table('customers')->where('user_id', $srcOwner->id)->first() ?? $fail('Staging owner has no customer profile.');
$srcBusiness = $stg->table('businesses')->where('workspace_id', $ws->id)->where('name', 'MotionGrove Internal')->orderBy('id')->first()
    ?? $fail('Staging Workspace has no Business "MotionGrove Internal".');
$srcLocation = $stg->table('business_locations')->where('business_id', $srcBusiness->id)->orderBy('id')->first() ?? $fail('Staging Business has no Location.');
$srcType = null;
if (! empty($stgEnv['DEMO_BOOKING_TYPE_UUID'])) {
    $srcType = $stg->table('booking_types')->where('public_booking_uuid', $stgEnv['DEMO_BOOKING_TYPE_UUID'])->where('business_location_id', $srcLocation->id)->first();
}
$srcType ??= $stg->table('booking_types')->where('business_location_id', $srcLocation->id)->where('name', 'MotionGrove Demo')->first()
    ?? $fail('Staging has no demo Booking Type at the internal Location.');
$srcHosts = $stg->table('booking_type_staff')->where('booking_type_id', $srcType->id)->pluck('staff_user_id')->all();
$srcForm = Schema::hasTable('demo_form_versions') ? $stg->table('demo_form_versions')->where('status', DemoFormVersion::PUBLISHED)->orderByDesc('id')->first() : null;
$srcAdmins = $stg->table('users')->where('is_admin', 1)->orderBy('id')->pluck('email')->all();
$srcRules = $stg->table('staff_availability_rules')->where('business_location_id', $srcLocation->id)->where('staff_user_id', $srcOwner->id)->orderBy('day_of_week')->orderBy('start_time')->get();
$srcTimeOff = $stg->table('staff_time_off')->where('staff_user_id', $srcOwner->id)->where('end_at', '>', now()->utc())->get();

$say();
$say('--- staging (read) ---');
$say('Platform Owner email(s) : '.implode(', ', $srcAdmins));
$say("internal owner          : {$srcOwner->email}  ({$srcOwner->first_name} {$srcOwner->last_name})");
$say("Business timezone       : {$srcBusiness->timezone}");
$say("Booking Type            : {$srcType->name}, {$srcType->duration_minutes} min");
$say('hosts on the type       : '.count($srcHosts).($srcHosts === [(int) $srcOwner->id] || $srcHosts === [(string) $srcOwner->id] ? ' (the owner only)' : ' (more than the owner: extra hosts are NOT transferred)'));
$say('published demo form      : '.($srcForm ? count((array) json_decode((string) $srcForm->fields, true)).' field(s)' : 'none (production keeps the design default)'));
$say("weekly hour windows     : {$srcRules->count()}   future time-off rows: {$srcTimeOff->count()}");

// ---------------------------------------------------------------- 2. read production
$say();
$say('--- production (read) ---');
$prodAdmins = User::query()->where('is_admin', true)->pluck('email')->all();
$say('Platform Owner exists   : '.($prodAdmins === [] ? 'NO' : 'yes ('.implode(', ', $prodAdmins).')'));
if ($prodAdmins === []) {
    $say('NEXT: run  bash deploy/forge/production/install-production.sh --owner-email='.($srcAdmins[0] ?? '<your-email>').'  first (it prompts for the password), then re-run this script.');
    if ($apply) {
        exit(1);
    }
}
foreach ($srcAdmins as $email) {
    if ($prodAdmins !== [] && ! in_array(strtolower($email), array_map('strtolower', $prodAdmins), true)) {
        $say("WARNING: staging administrator {$email} is not a production administrator (production has: ".implode(', ', $prodAdmins).').');
    }
}
$email = strtolower(trim((string) $srcOwner->email));
$prodOwner = User::query()->where('email', $email)->first();
$say('internal owner login    : '.($prodOwner ? 'exists (id '.$prodOwner->id.')' : 'will be created'));
if ($prodOwner && Customer::query()->where('user_id', $prodOwner->id)->doesntExist()) {
    $fail("{$email} exists on production but has no customer profile; refusing to convert it. Use a different customer login or fix it in the admin screens.");
}
if ($prodOwner && $prodOwner->is_admin) {
    $fail("{$email} is a production administrator; the internal Business must be owned by a customer login.");
}
$prodWorkspace = Workspace::query()->where('name', 'MotionGrove Internal')->first();
$say('MotionGrove Internal    : '.($prodWorkspace ? 'Workspace exists (reused)' : 'will be created by demo-booking:provision'));

if (! $apply) {
    $say();
    $say('--- settings that would be copied (column names; secrets/keys/ids are never copied) ---');
    foreach ([['businesses', $srcBusiness], ['business_locations', $srcLocation], ['booking_types', $srcType]] as [$table, $row]) {
        $cols = array_values(array_filter(array_keys((array) $row), fn ($c) => Schema::hasColumn($table, $c) && ! $neverCopy($c) && ! ($c === 'name' && $table !== 'business_locations')));
        $say(str_pad($table, 20).': '.implode(', ', $cols));
    }
    $say();
    $say('Dry run complete. Re-run with  --apply --confirm-database='.$prodDb.'  to write.');
    exit(0);
}

// ---------------------------------------------------------------- 3. owner login
if (! $prodOwner) {
    $password = $promptHidden("Password for the new production login {$email} (hidden): ");
    if ($password !== null) {
        if (strlen($password) < 12) {
            $fail('Use at least 12 characters.');
        }
        if ($promptHidden('Repeat the password: ') !== $password) {
            $fail('The passwords differ.');
        }
    } else {
        $password = Str::random(48);
        $say('No terminal: a random password was set and is not shown. Use "Forgot password" on the login page.');
    }
    $prodOwner = app(CustomerRepository::class)->store([
        'first_name' => $srcOwner->first_name,
        'last_name' => $srcOwner->last_name,
        'email' => $email,
        'status' => (bool) $srcOwner->status,
        'timezone' => $srcOwner->timezone,
        'locale' => $srcOwner->locale,
        'phone' => $srcCustomer->phone,
        'password' => $password,
    ]);
    unset($password);
    $say("created production login {$email} (id {$prodOwner->id}); no password, token or session was copied.");
}

// ---------------------------------------------------------------- 4. provision structure (existing command)
$code = Artisan::call('demo-booking:provision', array_filter([
    '--owner-email' => $email,
    '--timezone' => $srcBusiness->timezone,
    '--duration' => (int) $srcType->duration_minutes,
    '--confirm-database' => $prodDb,
    '--production' => app()->environment('production'),
]));
$say(trim(Artisan::output()));
if ($code !== 0) {
    $fail('demo-booking:provision refused (see above). Nothing further was changed.');
}

$workspace = Workspace::query()->where('name', 'MotionGrove Internal')->firstOrFail();
$business = Business::query()->where('workspace_id', $workspace->id)->where('name', 'MotionGrove Internal')->firstOrFail();
$location = BusinessLocation::query()->where('business_id', $business->id)->orderBy('id')->firstOrFail();
$type = BookingType::query()->where('business_location_id', $location->id)->where('name', 'MotionGrove Demo')->firstOrFail();

// ---------------------------------------------------------------- 5. settings columns
$say();
$say('--- settings ---');
foreach ([['businesses', $srcBusiness, $business], ['business_locations', $srcLocation, $location], ['booking_types', $srcType, $type]] as [$table, $src, $dst]) {
    $dstRow = (array) DB::table($table)->where('id', $dst->id)->first();
    $changes = [];
    foreach ($dstRow as $column => $old) {
        if (! property_exists($src, $column) || $neverCopy($column) || ($column === 'name' && $table !== 'business_locations')) {
            continue;
        }
        if ($src->$column !== $old && (string) $src->$column !== (string) $old) {
            $changes[$column] = $src->$column;
        }
    }
    if ($changes === []) {
        $say(str_pad($table, 20).': already identical');
        continue;
    }
    try {
        DB::table($table)->where('id', $dst->id)->update($changes + ['updated_at' => now()]);
        $say(str_pad($table, 20).': updated '.implode(', ', array_keys($changes)));
    } catch (Throwable $e) {
        $say(str_pad($table, 20).': NOT updated ('.class_basename($e).': '.Str::limit($e->getMessage(), 90).')');
    }
}
$type->refresh();

// ---------------------------------------------------------------- 6. weekly hours + time off
$say();
$say('--- weekly hours / time off ---');
$existing = StaffAvailabilityRule::query()->where('business_location_id', $location->id)->where('staff_user_id', $prodOwner->id)->count();
if ($srcRules->isEmpty()) {
    $say('staging has no weekly hours for the owner; nothing to copy.');
} elseif ($existing > 0 && ! isset($opts['replace-availability'])) {
    $say("production already has {$existing} window(s) for the owner: left unchanged (use --replace-availability to overwrite).");
} else {
    $week = [];
    foreach ($srcRules as $rule) {
        $week[(int) $rule->day_of_week][] = ['start' => substr((string) $rule->start_time, 0, 5), 'end' => substr((string) $rule->end_time, 0, 5)];
    }
    app(StaffAvailabilityService::class)->replaceWeek($workspace, $business, $location, (int) $prodOwner->id, $week, (int) $prodOwner->id);
    $say('weekly hours set from staging: '.$srcRules->count().' window(s) over '.count($week).' day(s).');
}
$added = 0;
foreach ($srcTimeOff as $off) {
    $exists = DB::table('staff_time_off')->where('staff_user_id', $prodOwner->id)->where('start_at', $off->start_at)->where('end_at', $off->end_at)->exists();
    if (! $exists) {
        DB::table('staff_time_off')->insert([
            'staff_user_id' => $prodOwner->id, 'start_at' => $off->start_at, 'end_at' => $off->end_at, 'reason' => $off->reason,
            'created_by_user_id' => $prodOwner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $added++;
    }
}
$say("future time off added: {$added}");

// ---------------------------------------------------------------- 6b. published demo form
$say();
$say('--- demo form ---');
if ($srcForm === null) {
    $say('staging has no published demo form (it uses the design default); production keeps the default too.');
} else {
    $srcFields = (array) json_decode((string) $srcForm->fields, true);
    $forms = app(DemoFormRepository::class);
    $live = $forms->published();
    $normalize = static fn (array $f): string => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $adminId = (int) User::query()->where('is_admin', true)->orderBy('id')->value('id');
    $liveStored = $live['id'] === null ? null : (array) DemoFormVersion::query()->find($live['id'])?->fields;
    if ($liveStored !== null && $normalize($liveStored) === $normalize($srcFields)) {
        $say('production already has this exact published form: unchanged.');
    } else {
        $forms->saveDraft($srcFields, $adminId);
        $forms->publish($adminId);
        $say('published the staging demo form on production ('.count($srcFields).' field(s)).');
    }
}

// ---------------------------------------------------------------- 7. DEMO_BOOKING_TYPE_UUID
$say();
$say('--- environment ---');
$uuid = (string) $type->public_booking_uuid;
if (! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
    $fail('Unexpected booking UUID format; .env not touched.');
}
$envPath = realpath((string) ($opts['prod-env'] ?? $root.'/.env')) ?: $fail('Production .env not found.');
$lines = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];
$found = false;
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*DEMO_BOOKING_TYPE_UUID\s*=/', $line)) {
        $lines[$i] = 'DEMO_BOOKING_TYPE_UUID='.$uuid;
        $found = true;
    }
}
if (! $found) {
    $lines[] = 'DEMO_BOOKING_TYPE_UUID='.$uuid;
}
$tmp = $envPath.'.tmp'.getmypid();
file_put_contents($tmp, implode("\n", $lines)."\n");
chmod($tmp, fileperms($envPath) & 0777);
rename($tmp, $envPath);
$say("DEMO_BOOKING_TYPE_UUID set to the production Booking Type {$uuid}");
Artisan::call('config:cache');
$say(trim(Artisan::output()));

// ---------------------------------------------------------------- 8. verify
$say();
$say('--- verification (production data) ---');
$check = static function (string $name, bool $ok) use ($say): void { $say(($ok ? '[ok]   ' : '[FAIL] ').$name); };
$business->refresh();
$statusValue = $business->status instanceof BackedEnum ? $business->status->value : (string) $business->status;
$check('Platform Owner exists', User::query()->where('is_admin', true)->exists());
$check("internal owner login exists and owns the Workspace ({$email})", (int) $workspace->owner_user_id === (int) $prodOwner->id);
$check('Business is active', $statusValue === 'active');
$check('Booking Type belongs to the internal Location/Business', (int) $type->business_location_id === (int) $location->id && (int) $location->business_id === (int) $business->id);
$check('Booking Type is active', $type->isActive());
$staffIds = $type->staff()->pluck('users.id')->map(fn ($v) => (int) $v)->all();
$check('the owner is the assigned host', in_array((int) $prodOwner->id, $staffIds, true));
$check('weekly hours exist for the host', StaffAvailabilityRule::query()->where('business_location_id', $location->id)->where('staff_user_id', $prodOwner->id)->exists());

$finder = app(PublicSlotFinder::class);
$tz = (string) $business->timezone;
$total = 0;
$first = [];
for ($d = 0; $d <= 21; $d++) {
    $date = now($tz)->addDays($d)->format('Y-m-d');
    $slots = $finder->slotsForDay($location, $type, $staffIds, $date, $tz, $tz);
    $total += count($slots);
    foreach ($slots as $s) {
        if (count($first) < 3) {
            $first[] = $date.' '.$s['label'];
        }
    }
}
$check("bookable slots in the next 21 days: {$total}".($first ? ' (first: '.implode('; ', $first).')' : ''), $total > 0);
try {
    $status = Http::timeout(25)->get(rtrim((string) config('app.url'), '/').'/book-demo')->status();
} catch (Throwable $e) {
    $status = 0;
}
$check("GET /book-demo -> HTTP {$status}", $status === 200);
$say();
$say('NOT tested here: an actual booking and the confirmation email. Make one test booking with your own address.');
