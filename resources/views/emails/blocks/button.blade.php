<tr>
    <td style="padding:12px 24px;text-align:{{ $block['align'] }};">
        <table role="presentation" cellpadding="0" cellspacing="0" align="{{ $block['align'] }}" style="border-collapse:separate;">
            <tr>
                <td style="border-radius:8px;background:{{ $block['bg_color'] }};text-align:center;">
                    @if ($block['href'])
                        <a href="{{ $block['href'] }}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:{{ $font }};font-size:16px;font-weight:bold;color:{{ $block['color'] }};text-decoration:none;border-radius:8px;">{{ $block['label'] }}</a>
                    @else
                        <span style="display:inline-block;padding:12px 28px;font-family:{{ $font }};font-size:16px;font-weight:bold;color:{{ $block['color'] }};">{{ $block['label'] }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </td>
</tr>
