<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use Tests\TestCase;

/**
 * Automations V2 (contract §19, §20.1, task requirement, V2-D) — source
 * scans over this slice's own files.
 *
 * Mirrors the established convention (tests/Feature/Analytics/AnalyticsSeparationTest.php)
 * of asserting a forbidden reference is absent from the SOURCE rather than
 * only from one rendered fixture — a stronger guarantee than any single
 * page-render test, because it holds regardless of what props a future
 * caller passes.
 */
class NoUnsupportedVocabularyTest extends TestCase
{
    /** @return list<string> */
    private function jsFiles(): array
    {
        $base = base_path('resources/js/automations/workflow-builder');

        $files = [];

        foreach (glob($base . '/*.js') as $file) {
            $files[] = $file;
        }

        $this->assertNotEmpty($files, 'The workflow-builder JS module must exist.');

        return $files;
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $base = base_path('resources/views/customer/Automations/Workflows');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'The V2-D Blade views must exist.');

        return $files;
    }

    /**
     * Comments stripped for both languages: the assertion is about code
     * paths (option values, config keys, imports), not about docblocks
     * that legitimately name what is forbidden — exactly the convention
     * tests/Feature/Analytics/AnalyticsSeparationTest.php already
     * established for PHP source scans in this repository.
     */
    private function stripBladeComments(string $source): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
    }

    private function stripJsComments(string $source): string
    {
        $noBlockComments = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return preg_replace('#^\s*//.*$#m', '', $noBlockComments) ?? $noBlockComments;
    }

    public function test_no_unsupported_domain_vocabulary_appears_in_the_builder_js(): void
    {
        $forbidden = ['booking', 'appointment', 'payment_received', 'invoice', 'quote', 'pipeline', 'crm_stage', 'webhook_action', 'ai_node', 'tag_added', 'email_to_contact'];

        foreach ($this->jsFiles() as $file) {
            $source = $this->stripJsComments(file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $source, "{$file} must never reference the unsupported domain \"{$needle}\" (contract §19 non-goals) in actual code.");
            }
        }
    }

    public function test_no_unsupported_domain_vocabulary_appears_in_the_builder_views(): void
    {
        $forbidden = ['booking', 'appointment', 'payment_received', 'invoice', 'quote', 'pipeline', 'webhook_action', 'tag_added'];

        foreach ($this->bladeFiles() as $file) {
            $source = $this->stripBladeComments(file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $source, "{$file} must never reference the unsupported domain \"{$needle}\" (contract §19 non-goals) in actual markup.");
            }
        }
    }

    /**
     * V2-D owns no PHP outside the lang file (contract §20.1's allowlist)
     * and must never construct a B4 model or call a B4 controller/action —
     * that would be editing B4 before V2-G, which the contract forbids
     * outright (§15.1).
     */
    public function test_no_b4_class_is_referenced_by_this_slices_views(): void
    {
        $forbidden = ['App\\Models\\Automation\\b', 'AutomationExecution', 'AutomationJob', 'SendAutomationMessage', 'Automation::STATUS_'];

        foreach ($this->bladeFiles() as $file) {
            $source = $this->stripBladeComments(file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file} must never reference the B4 runtime ({$needle}) — B4 stays untouched until V2-G.");
            }
        }
    }

    /** No graph/canvas library was introduced (contract §13.1/§13.2). */
    public function test_no_graph_or_canvas_library_is_referenced(): void
    {
        $forbidden = ['react-flow', 'xyflow', 'svelte-flow', 'drawflow', 'rete', 'jointjs', 'antv', 'dagre', 'elkjs', "from 'react'", 'from "react"', "from 'vue'", 'from "vue"'];

        foreach ($this->jsFiles() as $file) {
            $source = $this->stripJsComments(file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $source, "{$file} must never reference a graph library or a second framework ({$needle}) — contract §13.1/§13.2 forbid it.");
            }
        }

        foreach ($this->bladeFiles() as $file) {
            $source = $this->stripBladeComments(file_get_contents($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $source, "{$file} must never reference a graph library or a second framework ({$needle}) — contract §13.1/§13.2 forbid it.");
            }
        }
    }

    public function test_package_json_gained_no_new_dependency(): void
    {
        $packageJson = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $forbidden = ['react', 'react-dom', 'vue', '@xyflow/react', 'reactflow', 'svelte', 'drawflow', 'rete', 'jointjs', '@antv/x6', 'dagre', 'elkjs'];

        foreach (['dependencies', 'devDependencies'] as $bucket) {
            foreach (array_keys($packageJson[$bucket] ?? []) as $name) {
                $this->assertNotContains(strtolower($name), $forbidden, "package.json must not gain {$name} — contract §13.1 permits no graph library or second framework.");
            }
        }
    }
}
