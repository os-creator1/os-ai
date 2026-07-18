<!-- AI Message Modal -->
<div class="modal fade" id="aiMessageModal" tabindex="-1" aria-labelledby="aiMessageModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiMessageModalLabel">{{ __('locale.ai.generate_message') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-1">
                    <label for="aiGoal" class="form-label">{{ __('locale.ai.goal') }}?</label>
                    <input type="text" class="form-control" id="aiGoal"
                           placeholder="{{ __('locale.ai.goal_placeholder') }}">
                </div>
                <div class="mb-1">
                    <label for="aiTone" class="form-label">{{ __('locale.ai.tone') }}</label>
                    <select class="form-select" id="aiTone">
                        <option value="friendly">{{ __('locale.ai.friendly') }}</option>
                        <option value="professional">{{ __('locale.ai.professional') }}</option>
                        <option value="urgent">{{ __('locale.ai.urgent') }}</option>
                        <option value="casual">{{ __('locale.ai.casual') }}</option>
                    </select>
                </div>

                <div class="mb-1">
                    <label for="aiAudience" class="form-label">{{ __('locale.ai.audience') }}</label>
                    <input type="text" class="form-control" id="aiAudience"
                           placeholder="{{ __('locale.ai.audience_placeholder') }}">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary"
                        id="generateAiMessage">{{ __('locale.buttons.generate') }}</button>
            </div>
        </div>
    </div>
</div>
