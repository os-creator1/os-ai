{{--
    Implementation Contract 17 §6.3 — THE ONE UNIFORM REFUSAL.

    Rendered byte-identically for every failure reason: unknown uid, wrong
    token, rotated token, expired link, voided document, suspended or locked
    account, unentitled Business, unusable Location, no issued version.

    It must never say WHICH. Anything that varies with the reason — a
    different word, a different status, a different length — turns possession
    of a uid into an oracle for whether a document exists. Nothing on this
    page may be made dynamic.
--}}
<x-error-page code="404" />
