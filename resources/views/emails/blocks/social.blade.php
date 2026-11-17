<tr>
    <td style="padding:12px 24px;text-align:{{ $block['align'] }};">
        @foreach ($block['links'] as $link)
            <a href="{{ $link['href'] }}" target="_blank" style="display:inline-block;margin:4px;padding:6px 14px;border-radius:999px;background:{{ $block['color'] }};color:#ffffff;font-family:{{ $font }};font-size:13px;text-decoration:none;">{{ $link['label'] }}</a>
        @endforeach
    </td>
</tr>
