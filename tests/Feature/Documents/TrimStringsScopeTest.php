<?php

namespace Tests\Feature\Documents;

use App\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Contract 17B: the merge-field spacing exception is scoped to the editors'
 * block-save requests; global trimming is otherwise unchanged.
 */
class TrimStringsScopeTest extends TestCase
{
    private function run_through(string $method, string $uri, array $payload): array
    {
        $request = Request::create($uri, $method, $payload);
        $seen = [];
        (new TrimStrings())->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->all();

            return response('ok');
        });

        return $seen;
    }

    private function blocks(): array
    {
        return ['blocks' => [['id' => 'a', 'type' => 'text', 'data' => ['runs' => [['t' => 'Proposal for '], ['merge' => 'contact.first_name']]]]]];
    }

    public function test_editor_block_saves_keep_the_spaces_in_text_runs_but_trim_everything_else(): void
    {
        foreach (['customer/workspaces/w/businesses/b/documents/d/editor/blocks', 'customer/workspaces/w/businesses/b/document-templates/t/blocks', 'admin/document-templates/t/blocks'] as $uri) {
            $seen = $this->run_through('PUT', $uri, $this->blocks() + ['title' => '  Spaced title  ', 'name' => ' Name ']);

            $this->assertSame('Proposal for ', $seen['blocks'][0]['data']['runs'][0]['t'], $uri);
            $this->assertSame('Spaced title', $seen['title'], $uri);
            $this->assertSame('Name', $seen['name'], $uri);
        }
    }

    public function test_the_same_payload_on_any_other_request_is_trimmed_as_before(): void
    {
        $seen = $this->run_through('PUT', 'customer/workspaces/w/businesses/b/contacts/c', $this->blocks() + ['title' => ' x ']);
        $this->assertSame('Proposal for', $seen['blocks'][0]['data']['runs'][0]['t']);
        $this->assertSame('x', $seen['title']);

        $seen = $this->run_through('POST', 'customer/workspaces/w/businesses/b/documents/d/editor/blocks', $this->blocks());
        $this->assertSame('Proposal for', $seen['blocks'][0]['data']['runs'][0]['t'], 'only PUT block saves are exempt');

        $seen = $this->run_through('PUT', 'customer/workspaces/w/businesses/b/documents/d/editor/plan', $this->blocks());
        $this->assertSame('Proposal for', $seen['blocks'][0]['data']['runs'][0]['t'], 'other editor endpoints are not exempt');
    }

    public function test_the_exempt_flag_does_not_leak_into_the_next_request_and_passwords_stay_untouched(): void
    {
        $middleware = new TrimStrings();
        $next = function (Request $r) {
            return response()->json($r->all());
        };

        $middleware->handle(Request::create('customer/x/documents/d/editor/blocks', 'PUT', $this->blocks()), $next);
        $second = json_decode($middleware->handle(Request::create('customer/other', 'POST', $this->blocks() + ['password' => ' keep ', 'note' => ' trim ']), $next)->getContent(), true);

        $this->assertSame('Proposal for', $second['blocks'][0]['data']['runs'][0]['t']);
        $this->assertSame(' keep ', $second['password'], 'excepted attributes are still never trimmed');
        $this->assertSame('trim', $second['note']);
    }
}
