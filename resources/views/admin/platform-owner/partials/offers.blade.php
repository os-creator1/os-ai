{{--
    Private Offers for ONE Workspace — appropriate offer-management actions on
    the Workspace details screen. Expects $workspace and $workspaceOffers
    (PrivateOfferManager::overview() for this Workspace). Read-only here except
    for the links that open the owner's offer screens, where every mutation is
    reasoned and audited.
--}}
@isset($workspaceOffers)
    <x-card title="Private offers" data-testid="po-offers">
        <p class="text-muted">
            Special terms for this customer: a negotiated recurring price, a discount, or an offer to subscribe.
            A change to an existing subscription is explicit, audited, and — if it could ever cost them more —
            needs the customer's acceptance.
        </p>

        @can('manage workspace plans')
            <a class="btn btn-primary mb-1" data-testid="po-offers-create"
               href="{{ route('admin.platform-billing.offers.index', ['workspace' => $workspace->uid]) }}">
                Create an offer for this Workspace
            </a>
        @endcan

        @if (count($workspaceOffers) === 0)
            <p class="mb-0" data-testid="po-offers-none">No offers for this Workspace yet.</p>
        @else
            <div class="table-responsive">
                <table class="table mb-0" data-testid="po-offers-table">
                    <thead><tr><th>Offer</th><th>Terms</th><th>Status</th><th>Redeemed</th><th>Expires</th></tr></thead>
                    <tbody>
                    @foreach ($workspaceOffers as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.platform-billing.offers.show', [$row['offer']->uid]) }}">{{ $row['offer']->label }}</a>
                                @if ($row['offer']->isBillingChange()) <span class="badge bg-light-info">billing change</span> @endif
                            </td>
                            <td>{{ $row['summary'] }}</td>
                            <td>{{ ucfirst($row['status']) }}</td>
                            <td>{{ $row['redeemed'] }} / {{ $row['offer']->redemption_limit }}</td>
                            <td>{{ $row['offer']->expires_at->toFormattedDateString() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endisset
