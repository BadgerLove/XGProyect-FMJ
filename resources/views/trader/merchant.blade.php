<table width="665px">
    <tr>
        <td class="c">{{ __('game/trader.tr_resource_market') }}</td>
    </tr>
    <tr>
        <td>
            <table width="100%">
                <tr>
                    <td class="c">{{ __('game/trader.tr_merchant_title') }}</td>
                </tr>
                <tr>
                    <th>
                        <p>{{ __('game/trader.tr_merchant_intro') }}</p>
                    </th>
                </tr>
                <tr>
                    <th>
                        <table width="80%" style="margin: 0 auto;">
                            <tr>
                                <th style="text-align:right; width:40%;">{{ __('game/trader.tr_you_have') }}:</th>
                                <th style="text-align:left">{!! $available !!}</th>
                            </tr>
                            <tr>
                                <th style="text-align:right">{{ __('game/trader.tr_dm_cost') }}:</th>
                                <th style="text-align:left">{!! $dmCost !!}</th>
                            </tr>
                        </table>
                    </th>
                </tr>
                <tr>
                    <th>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="confirm_trade">
                            <input type="hidden" name="sell" value="{{ $sell }}">
                            <table width="80%" style="margin: 0 auto;">
                                <tr>
                                    <th style="text-align:right; width:40%;">
                                        {{ __('game/trader.tr_amount_to_sell') }}:
                                    </th>
                                    <th style="text-align:left">
                                        <input type="number" name="amount" id="trade-amount"
                                            min="1" max="{{ $maxAmount }}" value="{{ $maxAmount }}"
                                            style="width: 150px; text-align: right;"
                                            oninput="updatePreview()">
                                    </th>
                                </tr>
                                <tr>
                                    <th style="text-align:right">{{ __('game/trader.tr_exchange_for') }}:</th>
                                    <th style="text-align:left">
                                        @foreach ($buyOptions as $key => $option)
                                            <label style="margin-right: 15px;">
                                                <input type="radio" name="buy" value="{{ $key }}"
                                                    {{ $loop->first ? 'checked' : '' }}
                                                    onchange="updatePreview()">
                                                {{ $option['name'] }}
                                                ({{ $option['rateDisplay'] }})
                                            </label>
                                        @endforeach
                                    </th>
                                </tr>
                                <tr>
                                    <th style="text-align:right">{{ __('game/trader.tr_you_receive') }}:</th>
                                    <th style="text-align:left" id="preview-receive"></th>
                                </tr>
                                <tr>
                                    <th colspan="2" style="text-align:center; padding-top: 15px;">
                                        <input type="submit" value="{{ __('game/trader.tr_confirm_trade') }}">
                                        <a href="game.php?page=traderResources" style="margin-left: 20px;">{{ __('game/trader.tr_cancel') }}</a>
                                    </th>
                                </tr>
                            </table>
                        </form>
                    </th>
                </tr>
            </table>
        </td>
    </tr>
</table>
<script>
var rates = {!! json_encode($rates) !!};
var buyNames = {!! json_encode($buyNames) !!};

function updatePreview() {
    var amount = parseInt(document.getElementById('trade-amount').value) || 0;
    var buyEl = document.querySelector('input[name="buy"]:checked');
    if (!buyEl) return;
    var buy = buyEl.value;
    var rate = rates[buy] || 0;
    var result = Math.floor(amount * rate);
    document.getElementById('preview-receive').textContent = result.toLocaleString() + ' ' + buyNames[buy];
}
updatePreview();
</script>
