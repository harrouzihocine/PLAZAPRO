{{-- Branded shell for generated PDF documents. Values come from documents.meta
     (snapshotted at generation time) so reprints stay faithful. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document['number'] ?? 'Document' }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; margin: 0; }
        .wrap { padding: 36px 44px; }
        .brand { border-bottom: 3px solid #C9A227; padding-bottom: 12px; margin-bottom: 24px; }
        .brand .name { font-size: 22px; font-weight: bold; color: #111827; }
        .brand .tagline { font-size: 11px; color: #6b7280; letter-spacing: 1px; text-transform: uppercase; }
        .brand .contact { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .doc-title { float: right; text-align: right; }
        .doc-title .type { font-size: 16px; font-weight: bold; text-transform: uppercase; color: #C9A227; }
        .doc-title .number { font-size: 12px; color: #374151; margin-top: 2px; }
        .doc-title .meta { font-size: 10px; color: #6b7280; margin-top: 2px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin: 24px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 5px 0; vertical-align: top; }
        table.kv td.label { color: #6b7280; width: 40%; }
        table.lines th { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 8px 6px; color: #6b7280; font-size: 10px; text-transform: uppercase; }
        table.lines td { padding: 8px 6px; border-bottom: 1px solid #f3f4f6; }
        .amount { font-weight: bold; }
        .total-box { margin-top: 18px; border-top: 2px solid #C9A227; padding-top: 10px; }
        .total-box .row { padding: 3px 0; }
        .total-box .grand { font-size: 15px; font-weight: bold; }
        .text-right { text-align: right; }
        .footer { position: fixed; bottom: 24px; left: 44px; right: 44px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="doc-title">
            <div class="type">@yield('doc-type')</div>
            <div class="number">{{ $document['number'] ?? '' }}</div>
            <div class="meta">
                {{ $document['generated_at'] ?? '' }}
                @if(($document['version'] ?? 1) > 1) · v{{ $document['version'] }} @endif
            </div>
        </div>
        <div class="name">{{ $company['name'] ?? '' }}</div>
        <div class="tagline">{{ $company['tagline'] ?? '' }}</div>
        <div class="contact">
            {{ $company['address'] ?? '' }}
            @if(!empty($company['phone'])) · {{ $company['phone'] }} @endif
            @if(!empty($company['email'])) · {{ $company['email'] }} @endif
        </div>
    </div>

    @yield('body')
</div>

<div class="footer">
    {{ $company['name'] ?? '' }} — {{ $document['number'] ?? '' }} — Computer-generated document, no signature required.
</div>
</body>
</html>
