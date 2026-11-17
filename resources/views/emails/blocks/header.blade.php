<tr>
    <td style="background:{{ $block['bg_color'] }};padding:20px 24px;text-align:{{ $block['align'] }};">
        @if ($block['logo'] !== '')
            <img src="{{ $block['logo'] }}" alt="" height="48" style="display:inline-block;height:48px;width:auto;max-width:200px;border:0;">
            @if ($block['text'] !== '')<br>@endif
        @endif
        @if ($block['text'] !== '')
            <span style="font-family:{{ $font }};font-size:{{ $block['font_size'] }}px;line-height:1.4;font-weight:bold;color:{{ $block['color'] }};">{!! nl2br(e($block['text']), false) !!}</span>
        @endif
    </td>
</tr>
