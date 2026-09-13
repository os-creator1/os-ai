<?php

namespace App\Library\Automation\Workflow\Runtime;

/**
 * Automations V2-F §10.1 — which workflow step, if any, is sending right now.
 *
 * WHY THIS EXISTS INSTEAD OF A PARAMETER. An automation send goes through
 * `quickSend()`, and the customer-visible Reports row it produces is created in
 * one of many places depending on the transport: the managed delegate, or one
 * of several provider branches of the legacy campaign model. Threading a step
 * run id through every one of those would touch a dozen legacy call sites, and
 * the first branch anyone forgot would quietly produce untagged automation
 * output — exactly the failure the tag exists to prevent.
 *
 * So the SendSmsNodeExecutor opens a scope around its one send, and the Reports
 * model stamps every OUTBOUND row created inside that scope. One writer, every
 * transport, no branch left to forget.
 *
 * WHY IT IS SAFE. The scope is set and restored in `try/finally` around a single
 * synchronous call, so it cannot outlive the send that opened it, cannot leak
 * into the next job a long-lived worker runs, and a nested scope restores the
 * outer one. Outside a scope the current step run is null and nothing is
 * stamped — which is every campaign, every inbox reply, every API send.
 *
 * The id is the claimed step run's own primary key, never anything derived from
 * the message: this is identity, not inference.
 */
final class AutomationSendContext
{
    private ?int $stepRunId = null;

    /**
     * Run `$send` with every outbound Reports row it creates attributed to
     * `$stepRunId`.
     *
     * @template T
     * @param callable(): T $send
     * @return T
     */
    public function during(int $stepRunId, callable $send): mixed
    {
        $previous = $this->stepRunId;
        $this->stepRunId = $stepRunId;

        try {
            return $send();
        } finally {
            $this->stepRunId = $previous;
        }
    }

    /** The step run currently sending, or null outside any automation send. */
    public function currentStepRunId(): ?int
    {
        return $this->stepRunId;
    }
}
