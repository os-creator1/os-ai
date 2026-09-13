{{--
    Automations V2 (contract §5.2, V2-D) — End drawer. Deliberately
    configuration-free (NodeTypeRegistry::validateEnd() rejects anything
    else), so there is nothing to persist here beyond an explanatory note.
--}}
<template id="wf-node-form-end">
    <p class="text-caption mb-0">{{ __('automations.v2.builder.end_of_path') }}</p>
</template>
