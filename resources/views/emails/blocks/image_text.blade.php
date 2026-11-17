<tr>
    <td style="padding:8px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="{{ $direction }}">
            <tr>
                @foreach ($block['order'] as $cell)
                    @if ($cell === 'image')
                        <td class="mnjz-stack" width="40%" valign="top" style="width:40%;padding:4px;">
                            <img src="{{ $block['src'] }}" alt="{{ $block['alt'] }}" width="212" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:6px;">
                        </td>
                    @else
                        <td class="mnjz-stack" width="60%" valign="middle" style="width:60%;padding:4px 12px;text-align:{{ $block['text_align'] }};font-family:{{ $font }};font-size:{{ $block['font_size'] }}px;line-height:1.7;color:{{ $block['color'] }};">{!! nl2br(e($block['text']), false) !!}</td>
                    @endif
                @endforeach
            </tr>
        </table>
    </td>
</tr>
