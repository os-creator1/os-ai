<?php
/*
 * Browser-acceptance router for `php -S` (see README.md). Boots the app, signs in the user named by the qa_user cookie
 * (setUser — no session migration, so concurrent requests keep one session) and replaces the AI provider with a
 * scripted, deterministic client. NO real provider is ever reached. Test database only.
 */
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && file_exists($root . '/public' . $path) && is_file($root . '/public' . $path)) {
    return false; // static assets
}

putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';

$qa = json_decode(file_get_contents(__DIR__ . '/qa.json'), true);

final class ScriptedClient implements App\Library\Ai\Contracts\AiCompletionClient
{
    public function complete(App\Library\Ai\AiCompletionRequest $r): App\Library\Ai\AiCompletionResult
    {
        $msgs = $r->messages;
        $last = end($msgs)['content'] ?? '';
        $all = implode("\n", array_map(fn ($m) => $m['content'], $msgs));
        $q = '';
        foreach (array_reverse($msgs) as $m) {
            if (str_starts_with($m['content'], 'CUSTOMER MESSAGE')) { $q = strtolower($m['content']); break; }
        }
        $json = fn (array $a) => App\Library\Ai\AiCompletionResult::success(json_encode($a), 'gpt-4o-mini-2026', 1800, 150);
        $afterTools = str_starts_with($last, 'TOOL RESULTS');
        $tool = fn (string ...$t) => $json(['action' => 'call_tools', 'calls' => array_map(fn ($x) => ['tool' => $x, 'args' => new stdClass()], $t)]);

        if (str_contains($r->messages[0]['content'], 'Summarise this conversation')) {
            return App\Library\Ai\AiCompletionResult::success('The owner asked about SEO.', 'gpt-4o-mini', 300, 30);
        }
        if (str_contains($q, 'set up first')) {
            return $afterTools ? $json(['action' => 'answer', 'message' => "Start with the basics: your website is still a draft, so publishing it comes first. Then add a booking type so customers can book you.", 'suggestions' => ['Help me create a booking type', 'Is my website ready to go live?']]) : $tool('website_and_listings', 'schedule');
        }
        if (str_contains($q, 'booking type')) {
            if (str_contains($q, 'wedding')) {
                return $json(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Wedding package', 'duration_minutes' => 180], 'message' => 'Here is the booking type I would set up.']);
            }
            return $json(['action' => 'ask_user', 'message' => 'What should it be called, and how long does it last?']);
        }
        if (str_contains($q, 'wedding package')) {
            return $json(['action' => 'propose', 'tool' => 'create_booking_type', 'args' => ['name' => 'Wedding package', 'duration_minutes' => 180], 'message' => 'Here is the booking type I would set up.']);
        }
        if (str_contains($q, 'doing this month') || str_contains($q, 'results')) {
            return $afterTools ? $json(['action' => 'answer', 'message' => 'You gained more new contacts than in the period before it, and one customer is still waiting for a reply.', 'suggestions' => ['How is my SEO doing?']]) : $tool('business_snapshot');
        }
        if (str_contains($q, 'which keyword improved')) {
            return $afterTools ? $json(['action' => 'answer', 'message' => 'No keyword has a recorded improvement yet, because none is being tracked.', 'suggestions' => ['What should we do about the ones declining?']]) : $tool('seo_status');
        }
        if (str_contains($q, 'declining')) {
            return $json(['action' => 'answer', 'message' => 'Nothing is declining in the data I can see, so there is nothing to fix yet. Start tracking your main keywords so I can measure movement.']);
        }
        if (str_contains($q, 'seo')) {
            return $afterTools ? $json(['action' => 'answer', 'message' => 'Your SEO tracking is not set up with keywords yet, so I cannot report rankings.', 'suggestions' => ['Which keyword improved the most?']]) : $tool('seo_status');
        }
        return $json(['action' => 'answer', 'message' => 'I can help with that. What would you like to know?']);
    }
}

$app->booted(function () use ($app, $qa) {
    $app->instance(App\Library\Ai\Contracts\AiCompletionClient::class, new ScriptedClient());
    config(['services.openai.active' => true]);
});

final class QaLogin
{
    public function handle($request, Closure $next)
    {
        $id = $_COOKIE['qa_user'] ?? null;
        if ($id) {
            $qa = json_decode(file_get_contents(__DIR__ . '/qa.json'), true);
            $user = App\Models\User::find((int) $id);
            if ($user) {
                $user->forceFill(['email_verified_at' => now()])->save();
                Illuminate\Support\Facades\Auth::guard("web")->setUser($user);
                session(['permissions' => collect($qa['permissions'])]);
            }
        }
        return $next($request);
    }
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$groups = (fn () => $this->middlewareGroups)->call($kernel);
$web = $groups['web'];
$at = array_search(Illuminate\Session\Middleware\StartSession::class, $web, true);
array_splice($web, $at + 1, 0, [QaLogin::class]);
$groups['web'] = $web;
$kernel->setMiddlewareGroups($groups);
$response = $kernel->handle($request = Illuminate\Http\Request::capture());
$response->send();
$kernel->terminate($request, $response);
