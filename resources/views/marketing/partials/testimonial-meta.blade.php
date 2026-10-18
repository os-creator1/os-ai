{{-- Tag chip + name + business line for a slot ($s). $nameSize = px --}}
<span class="chip" style="align-self: flex-start; background: {{ $s['context'] === 'jazmin_media' ? '#EFEBE7' : '#E4F0EA' }}; color: {{ $s['context'] === 'jazmin_media' ? '#4A4340' : '#1F5E4A' }}; height: 22px; font-size: 11.5px">{{ $s['tag'] }}</span>
