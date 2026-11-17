<tr>
    <td style="padding:8px 24px;text-align:{{ $block['align'] }};">
        @if ($block['href'])<a href="{{ $block['href'] }}" target="_blank" style="text-decoration:none;">@endif
        <img src="{{ $block['src'] }}" alt="{{ $block['alt'] }}" width="{{ $block['width_px'] }}" style="display:inline-block;width:{{ $block['width'] }}%;max-width:100%;height:auto;border:0;border-radius:6px;">
        @if ($block['href'])</a>@endif
    </td>
</tr>
