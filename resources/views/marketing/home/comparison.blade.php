@php($c = $mg->get('copy.home.comparison'))
<section class="sec hide-m" aria-labelledby="cmp-h">
<div class="wrap" style="display: flex; flex-direction: column; gap: 48px">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 760px"><span class="eyebrow">{{ $c['eyebrow'] }}</span><h2 id="cmp-h" class="h2">{{ $c['title'] }}</h2></div>
<div style="overflow-x: auto; border-radius: 22px; border: 1px solid #E7E1DB; background: #FFFFFF">
<table style="width: 100%; min-width: 680px; border-collapse: collapse; font-size: 15.5px">
<thead><tr style="text-align: left"><th scope="col" style="padding: 22px 26px; font-size: 13px; color: #5E5652; font-weight: 600; width: 26%">What you need</th><th scope="col" style="padding: 22px 26px; font-size: 13px; color: #5E5652; font-weight: 600; width: 37%">Typical setup</th><th scope="col" style="padding: 22px 26px; width: 37%; background: #181413; color: #FFFFFF"><img src="{{ $mg->wordmark(true) }}" alt="{{ $mg->brandName() }}" style="height: 22px; width: auto; display: block"></th></tr></thead>
<tbody>
@foreach ($c['rows'] as [$need, $note, $typical, $strong, $rest])
<tr style="border-top: 1px solid #EFEAE5"><th scope="row" style="padding: 18px 26px; text-align: left; font-weight: 600">{{ $need }}@if ($note) <span style="font-weight: 400; color: #6B625E">{{ $note }}</span>@endif</th><td style="padding: 18px 26px; color: #5E5652">{{ $typical }}</td><td style="padding: 18px 26px; background: #FBF9F7"><b>{{ $strong }}</b>{{ $rest }}</td></tr>
@endforeach
</tbody>
</table>
</div>
<p style="margin: 0; font-size: 13px; color: #6B625E">{{ $c['note'] }} See <a href="{{ $mg->url('pricing') }}" style="text-decoration: underline">pricing</a> for what each plan includes.</p>
</div>
</section>
