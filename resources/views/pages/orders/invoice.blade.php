<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $order->order_number }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Embedded Unicode font for PDF reliability */
        @font-face {
            font-family: 'InvoiceUnicode';
            src: url('https://fonts.gstatic.com/s/notosans/v36/o-0IIpQlx3QUlC5A4PNr5TRASf6M7Q.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'InvoiceUnicode';
            src: url('https://fonts.gstatic.com/s/notosans/v36/o-0NIpQlx3QUlC5A4PNjXhFVZNyB.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        @page { margin: 0mm; size: A4; }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            color: #111827;
            line-height: 1.5;
        }
        /* PDF-specific complete override */
        @if(!empty($isPdf))
        :root { font-size: 14px; }  /* stabilize rems */
        html, body { margin: 0 !important; padding: 0 !important; }
        *, *::before, *::after { box-sizing: border-box; }
        
        body {
            background: #ffffff !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: 'InvoiceUnicode', 'Noto Sans', 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif !important;
            color: #000000 !important;
        }
        .invoice-container {
            box-shadow: none !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            page-break-inside: avoid !important;
        }
        .invoice-header {
            padding: 12px 16px 8px 16px !important;
            margin-bottom: 0 !important;
            display: table !important;
            width: 100% !important;
            border-bottom: 1px solid #e5e7eb !important;
            page-break-inside: avoid !important;
            page-break-after: avoid !important;
        }
        .logo-section {
            display: table-cell !important;
            vertical-align: top !important;
            width: 50% !important;
        }
        .logo {
            padding: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            background: none !important;
            display: inline-block !important;
        }
        .logo img {
            height: 50px !important;
            width: auto !important;
            max-width: 120px !important;
            display: block !important;
        }
        .company-details {
            display: table-cell !important;
            vertical-align: top !important;
            width: 50% !important;
            text-align: right !important;
            font-size: 9px !important;
            line-height: 1.4 !important;
            color: #6b7280 !important;
        }
        .company-details strong {
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .invoice-title {
            padding: 6px 16px !important;
            margin-bottom: 0 !important;
            text-align: center !important;
            page-break-inside: avoid !important;
            page-break-after: avoid !important;
        }
        .invoice-title h2 {
            margin: 0 !important;
            font-size: 20px !important;
            text-align: center !important;
            font-weight: 700 !important;
            color: #111827 !important;
            letter-spacing: 2px !important;
        }
        .invoice-info {
            padding: 6px 16px !important;
            page-break-inside: avoid !important;
        }
        .invoice-two-col {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        .invoice-col-left {
            width: 60% !important;
            vertical-align: top !important;
            padding-right: 10px !important;
        }
        .invoice-col-right {
            width: 40% !important;
            vertical-align: top !important;
            padding-left: 10px !important;
        }
        .invoice-block {
            margin-bottom: 8px !important;
        }
        .invoice-block h5.title {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            margin: 0 0 6px 0 !important;
        }
        .invoice-block p {
            font-size: 10px !important;
            line-height: 1.4 !important;
            color: #374151 !important;
            margin: 0 0 2px 0 !important;
        }
        .invoice-block p strong {
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .customer-info h3, .customer-section h3 {
            margin: 0 0 6px 0 !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }
        .customer-details {
            font-size: 10px !important;
            line-height: 1.4 !important;
            color: #374151 !important;
        }
        .customer-details div {
            margin-bottom: 2px !important;
        }
        .customer-name {
            font-size: 10px !important;
            font-weight: 600 !important;
            margin-bottom: 3px !important;
            color: #111827 !important;
        }
        .order-section {
            flex: 0 0 auto !important;
            min-width: 120px !important;
        }
        .order-box {
            border: 1px solid #e5e7eb !important;
            padding: 0 !important;
            background-color: #ffffff !important;
        }
        .order-box-header {
            display: table !important;
            width: 100% !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        .order-box-header-item {
            display: table-cell !important;
            width: 50% !important;
            padding: 4px 6px !important;
            text-align: center !important;
            font-size: 8px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
            background-color: #f9fafb !important;
        }
        .order-box-header-item:first-child {
            border-right: 1px solid #e5e7eb !important;
        }
        .order-box-content {
            display: table !important;
            width: 100% !important;
        }
        .order-box-content-item {
            display: table-cell !important;
            width: 50% !important;
            padding: 5px 6px !important;
            text-align: center !important;
            font-size: 9px !important;
            font-weight: 600 !important;
            color: #111827 !important;
        }
        .order-box-content-item:first-child {
            border-right: 1px solid #e5e7eb !important;
        }
        .products-table {
            margin: 6px 16px !important;
            width: calc(100% - 32px) !important;
            margin-bottom: 0 !important;
            border-collapse: collapse !important;
            border: 1px solid #e5e7eb !important;
            page-break-inside: auto !important;
        }
        .products-table thead {
            background-color: #374151 !important;
            page-break-inside: avoid !important;
        }
        .products-table th {
            padding: 6px 6px !important;
            font-size: 8px !important;
            font-weight: 700 !important;
            color: #ffffff !important;
            text-transform: uppercase !important;
            letter-spacing: 0.3px !important;
        }
        .products-table tbody tr {
            border-bottom: 1px solid #e5e7eb !important;
            page-break-inside: avoid !important;
        }
        .products-table tbody tr:last-child {
            border-bottom: none !important;
        }
        .products-table td {
            padding: 6px 6px !important;
            font-size: 9px !important;
            color: #374151 !important;
            vertical-align: top !important;
        }
        .product-name {
            font-size: 9px !important;
            font-weight: 600 !important;
            margin-bottom: 0 !important;
            color: #111827 !important;
        }
        .product-sku {
            font-size: 8px !important;
            color: #9ca3af !important;
        }
        .total-items-row {
            padding: 6px 16px !important;
            text-align: left !important;
            font-size: 9px !important;
            font-weight: 600 !important;
            color: #111827 !important;
            border-top: 1px solid #e5e7eb !important;
        }
        .totals-section {
            padding: 6px 16px 8px 16px !important;
            page-break-inside: avoid !important;
        }
        .totals-table {
            max-width: 150px !important;
            border-collapse: collapse !important;
            border: 1px solid #e5e7eb !important;
        }
        .totals-table tr {
            border-bottom: 1px solid #e5e7eb !important;
        }
        .totals-table tr:last-child {
            border-bottom: none !important;
        }
        .totals-table td {
            padding: 5px 8px !important;
            font-size: 9px !important;
        }
        .totals-table .label {
            font-weight: 700 !important;
            color: #111827 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.2px !important;
            font-size: 8px !important;
            text-align: right !important;
        }
        .totals-table .amount {
            font-weight: 600 !important;
            color: #111827 !important;
            text-align: right !important;
            width: 60px !important;
        }
        .totals-table .total-row {
            background-color: #f9fafb !important;
        }
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            padding: 6px 8px !important;
        }
        .footer {
            padding: 6px 16px !important;
            font-size: 8px !important;
            color: #374151 !important;
            background-color: #f9fafb !important;
            border-top: 1px solid #e5e7eb !important;
            margin-top: 0 !important;
            page-break-inside: avoid !important;
        }
        .footer-message {
            font-size: 9px !important;
            margin-bottom: 4px !important;
            font-weight: 500 !important;
            color: #374151 !important;
            font-style: italic !important;
        }
        .footer-contact {
            font-size: 8px !important;
            color: #6b7280 !important;
            line-height: 1.4 !important;
        }
        
        /* PDF-friendly table layout for reliable alignment */
        .invoice-two-col {
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            margin-bottom: 12px !important;
        }
        .invoice-two-col td {
            vertical-align: top !important;
            padding: 0 !important;
        }
        .invoice-col-left { width: 58% !important; }
        .invoice-col-right { width: 42% !important; }
        
        .invoice-block h5, .invoice-block .title {
            margin: 0 0 6px 0 !important;
            font-size: 12px !important;
            font-weight: bold !important;
            color: #000000 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }
        .invoice-block p {
            margin: 0 0 4px 0 !important;
            line-height: 1.3 !important;
            font-size: 11px !important;
            color: #000000 !important;
        }
        
        /* Fixed table layout for products */
        .products-table {
            table-layout: fixed !important;
            width: calc(100% - 24px) !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .products-table th.col-product, .products-table td.col-product { width: 56% !important; white-space: normal !important; }
        .products-table th.col-qty, .products-table td.col-qty { width: 12% !important; text-align: center !important; }
        .products-table th.col-price, .products-table td.col-price { width: 14% !important; text-align: center !important; }
        .products-table th.col-total, .products-table td.col-total { width: 18% !important; text-align: right !important; }
        
        @endif

        /* WhatsApp-capture compact layout (?wa=1, Aug-2026).
           Only the hidden-iframe html2canvas captures pass this flag (the
           server appends it in WhatsAppWebController::getInvoiceImageUrl).
           Narrower sheet + larger type so the image stays readable when a
           phone fits it to screen width — 13px text in the 800px layout
           lands at ~6 logical px fitted, which customers report as
           "blurred". Print, PDF and the customer app never pass ?wa=1. */
        @if(request('wa') == '1')
        .invoice-container { max-width: 560px !important; box-shadow: none !important; }
        body { padding: 0 !important; background: #ffffff !important; }
        .invoice-header { padding: 14px 18px 10px 18px !important; min-height: 0 !important; }
        .logo img { height: 64px !important; }
        .company-details { font-size: 10px !important; line-height: 1.5 !important; }
        .invoice-title { padding: 6px 18px !important; }
        .invoice-title h2 { font-size: 22px !important; letter-spacing: 1px !important; }
        .invoice-info { padding: 10px 18px !important; gap: 14px !important; }
        .customer-section h3 { font-size: 11px !important; margin-bottom: 6px !important; }
        .customer-details { font-size: 14px !important; line-height: 1.35 !important; }
        .order-section { min-width: 170px !important; }
        .order-box-header-item { padding: 5px 6px !important; font-size: 9px !important; }
        .order-box-content-item { padding: 6px 6px !important; font-size: 12px !important; }
        .products-table { width: calc(100% - 32px) !important; margin: 10px 16px !important; }
        .products-table th { padding: 8px 7px !important; font-size: 10px !important; letter-spacing: 0.4px !important; }
        .products-table td { padding: 9px 7px !important; font-size: 15px !important; }
        .product-name { font-size: 15px !important; margin-bottom: 2px !important; }
        .product-sku { font-size: 10px !important; }
        .total-items-row { padding: 8px 16px !important; font-size: 12px !important; }
        .totals-section { padding: 8px 16px 14px 16px !important; }
        .totals-table { max-width: 290px !important; }
        .totals-table td { padding: 7px 10px !important; font-size: 14px !important; }
        .totals-table .label { font-size: 12px !important; }
        .totals-table .total-row .label, .totals-table .total-row .amount { font-size: 16px !important; padding: 9px 10px !important; }
        .footer { padding: 10px 16px !important; margin-top: 8px !important; }
        .footer-message { font-size: 12px !important; margin-bottom: 4px !important; }
        .footer-contact { font-size: 10px !important; line-height: 1.45 !important; }
        @endif
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .invoice-header {
            background-color: white;
            padding: 30px 40px 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
            min-height: 140px;
        }
        
        .logo-section {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        
        .logo img {
            height: 140px;
            width: auto;
            object-fit: contain;
        }
        
        .company-details {
            flex: 0 0 auto;
            text-align: right;
            font-size: 12px;
            line-height: 1.8;
            color: #6b7280;
        }
        
        .company-details div {
            margin-bottom: 2px;
        }
        
        .company-details strong {
            color: #111827;
            font-weight: 600;
        }
        
        .invoice-title {
            background-color: white;
            padding: 20px 40px;
            text-align: center;
        }
        
        .invoice-title h2 {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
            text-align: center;
            letter-spacing: 2px;
            margin: 0;
        }
        
        .invoice-info { 
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 40px;
        }
        
        .customer-section {
            flex: 1;
        }
        
        .order-section {
            flex: 0 0 auto;
            min-width: 280px;
        }
        
        .customer-section h3 {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .customer-details {
            font-size: 13px;
            line-height: 1.4;
            color: #374151;
        }
        
        .customer-details div {
            margin-bottom: 2px;
        }
        
        .customer-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 6px;
        }
        
        /* Order Details Box */
        .order-box {
            border: 2px solid #e5e7eb;
            padding: 0;
            background-color: #ffffff;
        }
        
        .order-box-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .order-box-header-item {
            flex: 1;
            padding: 10px 15px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background-color: #f9fafb;
        }
        
        .order-box-header-item:first-child {
            border-right: 1px solid #e5e7eb;
        }
        
        .order-box-content {
            display: flex;
        }
        
        .order-box-content-item {
            flex: 1;
            padding: 12px 15px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        
        .order-box-content-item:first-child {
            border-right: 1px solid #e5e7eb;
        }
        
        .products-table {
            width: calc(100% - 80px);
            margin: 20px 40px;
            border-collapse: collapse;
            border: 1px solid #e5e7eb;
        }
        
        .products-table thead {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            color: white;
        }
        
        .products-table th {
            padding: 14px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .products-table th.text-center {
            text-align: center;
        }
        
        .products-table th.text-right {
            text-align: right;
        }
        
        .products-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .products-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .products-table td {
            padding: 14px 12px;
            font-size: 13px;
            color: #374151;
            vertical-align: top;
        }
        
        .products-table td.text-center {
            text-align: center;
        }
        
        .products-table td.text-right {
            text-align: right;
        }
        
        .product-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            font-size: 13px;
        }
        
        .product-sku {
            font-size: 11px;
            color: #9ca3af;
        }
        
        /* Total Item Number Row */
        .total-items-row {
            padding: 15px 40px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            border-top: 1px solid #e5e7eb;
        }
        
        .totals-section {
            padding: 20px 40px 30px 40px;
        }
        
        .totals-table {
            width: 100%;
            max-width: 350px;
            margin-left: auto;
            border-collapse: collapse;
            border: 1px solid #e5e7eb;
        }
        
        .totals-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .totals-table tr:last-child {
            border-bottom: none;
        }
        
        .totals-table td {
            padding: 12px 20px;
            font-size: 13px;
        }
        
        .totals-table .label {
            text-align: right;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 12px;
        }
        
        .totals-table .amount {
            text-align: right;
            color: #111827;
            font-weight: 600;
            width: 140px;
        }
        
        .totals-table .total-row {
            background-color: #f9fafb;
        }
        
        .totals-table .total-row .label,
        .totals-table .total-row .amount {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            padding: 15px 20px;
        }
        
        .footer {
            background-color: #f9fafb;
            padding: 25px 40px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        
        .footer-message {
            font-size: 14px;
            color: #374151;
            margin-bottom: 10px;
            font-weight: 500;
            font-style: italic;
        }
        
        .footer-contact {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.6;
        }
        
        /* Hide UI-only chrome (edit stamp link, modal) from print/PNG captures */
        .no-print { /* keep visible on screen; hidden below */ }
        @media print {
            .no-print { display: none !important; }
            body {
                padding: 0;
                background-color: white !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .invoice-container {
                box-shadow: none;
                max-width: none;
            }
            
            .products-table thead {
                background-color: #2d3748 !important;
                color: white !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .totals-table .total-row {
                background-color: #f7fafc !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            .footer {
                background-color: #f7fafc !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
@php 
$autoPrint = request('print_pdf') == '1'; 
$autoPng = request('auto_png') == '1'; 
$viewAndDownloadPng = request('view_and_download_png') == '1';
$isPngDownload = $autoPng || $viewAndDownloadPng;
$hideUnitPrice = request('hide_unit_price') == '1';
@endphp
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-section" style="flex:1; display:flex; align-items:flex-start;">
                <div class="logo">
                    <!-- Try multiple logo paths with debugging -->
                <div id="logoContainer" style="width: 100%; height: 100%; position: relative;">
@php
    // Determine a valid logo path and embed as base64 for PDF reliability
    $webLogo = asset('assets/media/logos/nizami-farms-logo.png');
    $paths = [
        public_path('assets/media/logos/nizami-farms-logo.png'),
        public_path('assets/media/logos/nizami-farms-logo.jpg'),
        public_path('assets/media/logos/nizami-farms-logo.png.jpg'),
    ];
    $logoDataUri = null;
    foreach ($paths as $p) {
        if (is_file($p)) {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
            break;
        }
    }
@endphp
                    @if((!empty($isPdf) || $isPngDownload) && $logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="Nizami Farms" style="height: 42px; width: auto; display: block;">
                    @else
                        <!-- Preserve robust web fallback with JS as it was working -->
                        <img id="logoImage" src="{{ $webLogo }}" alt="Nizami Farms" 
                             style="border-radius: 6px; max-width: 100%; max-height: 100%;"
                             onerror="tryNextLogo(this)">
                        <script>
                        const logoPaths = [
                            '{{ asset('assets/media/logos/nizami-farms-logo.png') }}',
                            '{{ asset('assets/media/logos/nizami-farms-logo.jpg') }}',
                            '{{ asset('assets/media/logos/nizami-farms-logo.png.jpg') }}',
                            '{{ asset('assets/media/app/nizami-logo.png') }}',
                            '{{ asset('assets/media/logos/logo.png') }}',
                            '{{ asset('logo.png') }}'
                        ];
                        let currentLogoIndex = 0;
                        function tryNextLogo(img){
                            currentLogoIndex++;
                            if(currentLogoIndex < logoPaths.length){ img.src = logoPaths[currentLogoIndex]; }
                        }
                        </script>
                    @endif
                </div>
                </div>
                <!-- Company info should come from logo image, not hardcoded text -->
            </div>
            <div class="company-details" style="flex:1; text-align:right; align-self:flex-start; margin-top:0;">
                <div><strong>NTN: A02148-1</strong></div>
                <div>F-12, Rehman Arcade</div>
                <div>Aabpara Market, G-6/1</div>
                <div>Islamabad</div>
                <div>www.nizamifarms.com</div>
                <div>Ph: 0305-5300905</div>
                <div>0320-0NIZAMI (0649264)</div>
            </div>
        </div>
        
        <!-- Invoice Title -->
        <div class="invoice-title">
            <h2>INVOICE</h2>
        </div>
        
        <!-- Invoice Information -->
        @php
            // ⭐ PRIORITY: Use ORDER address fields (for order-specific overrides)
            // Fallback to customer table only if order fields are empty
            $custFirstName = $order->address_first_name ?: ($order->customer->first_name ?? '');
            $custLastName = $order->address_last_name ?: ($order->customer->last_name ?? '');
            $custName = trim("$custFirstName $custLastName");
            
            // If still empty, try order.name field
            if (empty($custName) && $order->name) {
                $custName = $order->name;
            }
            
            // Address fields - order first, then customer fallback
            $address1 = $order->address_line1 ?: ($order->customer->address1 ?? '');
            $address2 = $order->address_line2 ?: ($order->customer->address2 ?? '');
            $city = $order->address_city ?: ($order->customer->city ?? '');
            $province = $order->address_province ?: ($order->customer->province ?? '');
            $postalCode = $order->address_postal_code ?: ($order->customer->postal_code ?? '');
            $phone = $order->address_phone ?: ($order->customer->phone_original ?? '');

            // ✂ Split invoice (Oct-2026): one line telling the customer this invoice is
            // part of a split. ⚠ LIVE orders only — a Shopify staging row shares ids with
            // live orders, so it must never be looked up here.
            $splitNote = null;
            if ($order instanceof \App\Models\CRM\OrderModel) {
                $splitRel = \App\Services\CRM\OrderSplitService::relationsFor([$order->id])[$order->id] ?? null;
                if (!empty($splitRel['split_from'])) {
                    $splitNote = 'Split from ' . $splitRel['split_from'] . ' — the rest of that order';
                } elseif (!empty($splitRel['split_into'])) {
                    $splitNote = 'Some items moved to ' . implode(', ', $splitRel['split_into']);
                }
            }
        @endphp
        @if(!empty($isPdf))
        <div class="invoice-info">
            <table class="invoice-two-col">
                <tr>
                    <td class="invoice-col-left">
                        <div class="invoice-block">
                            <h5 class="title">Customer Details</h5>
                            <p><strong>{{ $custName }}</strong></p>
                            @if($address1)
                                <p>{{ $address1 }}</p>
                                @if($address2)
                                    <p>{{ $address2 }}</p>
                                @endif
                                <p>{{ $city }}@if($province), {{ $province }}@endif</p>
                                @if($postalCode)
                                    <p>{{ $postalCode }}</p>
                                @endif
                            @endif
                            @if($phone)
                                <p>{{ $phone }}</p>
                            @endif
                        </div>
                    </td>
                    <td class="invoice-col-right">
                        <div class="invoice-block" style="text-align: right;">
                            <h5 class="title">Order Summary</h5>
                            <p><strong>Order No:</strong> {{ $order->order_number }}</p>
                            <p><strong>Order Date:</strong> {{ \Carbon\Carbon::parse($order->order_date)->format('j F Y') }}</p>
                            @if($splitNote)
                                <p style="font-size: 11px; color: #92400e;">{{ $splitNote }}</p>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        @else
        <div class="invoice-info">
            <div class="customer-section">
                <h3>Customer Details</h3>
                <div class="customer-details">
                    <div class="customer-name">{{ $custName }}</div>
                    @if($address1)
                        <div>{{ $address1 }}</div>
                        @if($address2)
                            <div>{{ $address2 }}</div>
                        @endif
                        <div>{{ $city }}@if($province), {{ $province }}@endif</div>
                        @if($postalCode)
                            <div>{{ $postalCode }}</div>
                        @endif
                    @endif
                    @if($phone)
                        <div>{{ $phone }}</div>
                    @endif
                </div>
            </div>
            <div class="order-section">
                <div class="order-box">
                    <div class="order-box-header">
                        <div class="order-box-header-item">Order No</div>
                        <div class="order-box-header-item">Order Date</div>
                    </div>
                    <div class="order-box-content">
                        <div class="order-box-content-item">{{ $order->order_number }}</div>
                        <div class="order-box-content-item">{{ \Carbon\Carbon::parse($order->order_date)->format('j F Y') }}</div>
                    </div>
                    @if($splitNote)
                        <div style="padding: 6px 10px; font-size: 12px; color: #92400e; background: #fffbeb; border-top: 1px solid #fde68a; text-align: center;">✂ {{ $splitNote }}</div>
                    @endif
                </div>
            </div>
        </div>
        @endif
        
        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    @if($hideUnitPrice)
                    <th style="width: 60%;">PRODUCTS</th>
                    <th class="text-center" style="width: 20%;">Qty</th>
                    <th class="text-right" style="width: 20%;">Total</th>
                    @else
                    <th style="width: 55%;">PRODUCTS</th>
                    <th class="text-center" style="width: 12%;">Qty</th>
                    <th class="text-center" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 18%;">Total</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($order->lineItems as $item)
                @php
                    $isQurbaniInvoice = !empty($qurbaniInvoiceFields ?? []);
                    $hasItemNote = $isQurbaniInvoice && !empty(trim((string)($item->instructions ?? '')));
                @endphp
                <tr{!! $hasItemNote ? ' style="background-color: #fefce8;"' : '' !!}>
                    <td>
                        <div class="product-name">{{ $item->name ?: 'N/A' }}@if($item->is_free) <span style="display: inline-block; padding: 1px 6px; background: #dcfce7; color: #16a34a; border-radius: 3px; font-size: 9px; font-weight: 700; margin-left: 4px;">FREE</span>@endif</div>
                        @if($isQurbaniInvoice)
                        @php
                            $attrParts = [];
                            $fieldMap = ['qurbani_day' => 'Day', 'qurbani_delivery_type' => 'Type', 'qurbani_slot' => 'Slot', 'qurbani_region' => 'Region', 'qurbani_sub_region' => 'Sub Region', 'qurbani_type' => 'Qurbani Type', 'qurbani_paya' => 'Paya'];
                            foreach ($qurbaniInvoiceFields as $f) {
                                $val = $item->{$f} ?? null;
                                if ($val) $attrParts[] = ($fieldMap[$f] ?? $f) . ': ' . $val;
                            }
                        @endphp
                        @if(count($attrParts) > 0)
                        <div style="font-size: 11px; color: #92400e; margin-top: 4px; font-weight: 500; line-height: 1.5;">
                            @foreach($attrParts as $attrPart)
                                <div>{{ $attrPart }}</div>
                            @endforeach
                        </div>
                        @endif
                        @endif
                        @if($hasItemNote)
                        <div style="font-size: 9px; color: #92400e; margin-top: 2px; font-style: italic;">📝 {{ $item->instructions }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format($item->quantity, ($item->quantity == floor($item->quantity)) ? 0 : 3) }}</td>
                    @if(!$hideUnitPrice)
                    <td class="text-center">@if($item->is_free)<s style="color:#aaa;">Rs {{ number_format($item->unit_price, 0) }}</s>@else Rs {{ number_format($item->unit_price, 0) }}@endif</td>
                    @endif
                    <td class="text-right" style="font-weight: 600;">@if($item->is_free)<span style="color: #16a34a; font-weight: 700;">FREE</span>@else Rs {{ number_format($item->line_total ?: ($item->quantity * $item->unit_price), 0) }}@endif</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Total Item Number -->
        <div class="total-items-row">
            <strong>TOTAL ITEM NUMBER:</strong> {{ $order->lineItems->count() }}
        </div>

        @if(!empty($qurbaniInvoiceFields ?? []) && !empty(trim((string)($order->note ?? ''))))
        <div style="margin: 8px 0; padding: 8px 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 6px;">
            <div style="font-size: 11px; color: #92400e; font-weight: 600;">📝 Order Notes:</div>
            <div style="font-size: 11px; color: #78350f; margin-top: 2px;">{{ $order->note }}</div>
        </div>
        @endif
        
        <!-- Totals + PAID stamp (stamp renders only when fully paid) -->
        @php
            $__paidStampData = $order->getPaidStampData();
            $__canEditStamp  = auth()->check(); // stamp is a display toggle, any logged-in user can tweak it
        @endphp
        <div class="totals-section" style="position:relative;">
            @if ($__paidStampData['show'])
                <div class="paid-stamp-wrap" style="float:left; padding-left:40px; padding-top:10px; max-width:45%;">
                    @include('pages.orders.partials.paid-stamp', ['order' => $order, 'paidStamp' => $__paidStampData])
                    @if ($__canEditStamp)
                        <div class="paid-stamp-edit-link no-print" style="margin-top:8px; transform:rotate(-8deg); text-align:center;">
                            <a href="#" onclick="openPaidStampEditor(event)"
                               style="font-size:11px; color:#7F1D1D; text-decoration:none; font-family:Arial,Helvetica,sans-serif;">
                                ✏️ Edit stamp
                            </a>
                        </div>
                    @endif
                </div>
            @endif
            <table class="totals-table">
                @php
                    $discountBreakdown = $order->getDiscountBreakdown();
                    $totalDiscounts = $discountBreakdown->sum('discount_amount');
                    $subtotal = $order->lineItems->sum(function($item) { return $item->is_free ? 0 : ($item->line_total ?: ($item->quantity * $item->unit_price)); });
                @endphp
                
                {{-- ⭐ The customer's own account balance is NOT a discount and is
                     never printed as one. Split out so the invoice reads honestly;
                     the two lines still sum to the same reduction, so the total is
                     unchanged. --}}
                @php
                    $__balanceUsed = $order->accountBalanceApplied();
                    $__realDiscount = round($totalDiscounts - $__balanceUsed, 2);
                @endphp
                @if($__realDiscount > 0)
                    <tr>
                    <td class="label">Discount</td>
                    <td class="amount">- Rs {{ number_format($__realDiscount, 0) }}</td>
                    </tr>
                @endif
                @if($__balanceUsed > 0)
                    <tr>
                    <td class="label">Account balance used</td>
                    <td class="amount">- Rs {{ number_format($__balanceUsed, 0) }}</td>
                    </tr>
                @endif
                
                <tr>
                    <td class="label">Sub Total</td>
                    <td class="amount">Rs {{ number_format($subtotal, 0) }}</td>
                </tr>
                
                @php
                    // Qurbani self-collection rule: when the customer is
                    // picking up (not delivery) AND no shipping amount was
                    // entered, suppress the row entirely. Showing
                    // "Free Delivery" in that case wrongly implies we are
                    // offering free delivery on a self-pickup order.
                    // Otherwise behaviour is unchanged: positive shipping
                    // shows the amount, zero shipping shows "Free Delivery".
                    $qSelfCollect = function ($v) {
                        $s = strtolower(trim((string)($v ?? '')));
                        return $s !== '' && str_contains($s, 'self');
                    };
                    $hasAnyQurbaniDT = false;
                    $allQurbaniDTSelfCollect = true;
                    foreach (($order->lineItems ?? []) as $__li) {
                        $__dt = $__li->qurbani_delivery_type ?? null;
                        if ($__dt) {
                            $hasAnyQurbaniDT = true;
                            if (!$qSelfCollect($__dt)) { $allQurbaniDTSelfCollect = false; }
                        }
                    }
                    if (!$hasAnyQurbaniDT && !empty($order->qurbani_delivery_type)) {
                        $hasAnyQurbaniDT = true;
                        $allQurbaniDTSelfCollect = $qSelfCollect($order->qurbani_delivery_type);
                    }
                    $hideShippingRow = $hasAnyQurbaniDT && $allQurbaniDTSelfCollect
                                       && (float)($order->shipping_total ?? 0) <= 0;
                @endphp
                @if($order->shipping_total && $order->shipping_total > 0)
                <tr>
                    <td class="label">Shipping</td>
                    <td class="amount">Rs {{ number_format($order->shipping_total, 0) }}</td>
                </tr>
                @elseif(!$hideShippingRow)
                <tr>
                    <td class="label">Shipping</td>
                    <td class="amount" style="color: #059669; font-weight: 600;">Free Delivery</td>
                </tr>
                @endif
                
                @if(isset($order->tip_amount) && $order->tip_amount > 0)
                <tr>
                    <td class="label">Tip</td>
                    <td class="amount">Rs {{ number_format($order->tip_amount, 0) }}</td>
                </tr>
                @endif
                
                @php
                    // Calculate actual total including tip (in case stored total_price doesn't include it)
                    $calculatedTotal = $subtotal - $totalDiscounts + ($order->shipping_total ?? 0) + ($order->tip_amount ?? 0);
                    // Use the higher of stored total or calculated total (to avoid showing less than owed)
                    $displayTotal = max($order->total_price ?? 0, $calculatedTotal);
                @endphp
                
                <tr class="total-row">
                    <td class="label">Total</td>
                    <td class="amount">Rs {{ number_format($displayTotal, 0) }}</td>
                </tr>
            </table>
            <div style="clear:both;"></div>
        </div>
        
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-message">
                You have trusted us to serve you the best meat in town, thank you!
            </div>
            <div class="footer-contact">
                Follow us on Facebook & Instagram: @nizamifarms, in case of complaints, please contact: 0305-5300905 or 0320-0NIZAMI (0649264) or write to<br>
                us at: support@nizamifarms.com
            </div>
        </div>
    </div>
<script>
// Auto print-to-PDF for exact browser rendering
(function(){
  const url = new URL(window.location.href);
  if (url.searchParams.get('print_pdf') === '1') {
    // Auto-download PDF using server-side generation for better formatting
    const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
    const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
    const customerName = firstName + (lastName ? ' ' + lastName : '');
    const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
    const orderNumber = '{{ $order->order_number }}';
    const filename = customerName + ' ' + phoneNumber + ' ' + orderNumber;
    const pdfUrl = '/orders/{{ $order->id }}/invoice/pdf?auto_pdf=1&filename=' + encodeURIComponent(filename);
    
    // Try server-side PDF generation first
    fetch(pdfUrl)
      .then(response => {
        if (response.ok) {
          // Server-side PDF generation successful, trigger download
          const link = document.createElement('a');
          link.href = pdfUrl;
          link.download = filename + '.pdf';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          
          // Close tab after successful download
          setTimeout(() => { window.close(); }, 1000);
        } else {
          throw new Error('Server PDF generation failed');
        }
      })
      .catch(error => {
        console.log('Server PDF failed, falling back to browser print:', error);
        // Fallback to browser print dialog
        document.title = 'Invoice-{{ $order->order_number }}';
        setTimeout(() => { 
          window.print(); 
          // Don't close tab automatically for print dialog
        }, 300);
      });
  }

  if (url.searchParams.get('auto_png') === '1') {
    // Use HTMLCanvas + drawWindow via html2canvas for exact screenshot
    const addScript = (src) => new Promise(r => { const s = document.createElement('script'); s.src = src; s.onload = r; document.head.appendChild(s); });
    (async () => {
      // Load html2canvas from CDN once
      if (!window.html2canvas) {
        await addScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
      }
      const node = document.querySelector('.invoice-container');
      // Hide edit-stamp chrome before rasterising
      const hideEls = node.querySelectorAll('.no-print');
      hideEls.forEach(el => el.style.visibility = 'hidden');
      const canvas = await window.html2canvas(node, {scale: 2, useCORS: true});
      hideEls.forEach(el => el.style.visibility = '');
      const link = document.createElement('a');
      link.href = canvas.toDataURL('image/png');
      const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
      const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
      const customerName = firstName + (lastName ? ' ' + lastName : '');
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + ' ' + phoneNumber + ' ' + orderNumber + '.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // Close the helper tab/window automatically
      setTimeout(() => { window.close(); }, 500);
    })();
  }

  if (url.searchParams.get('view_and_download_png') === '1') {
    // Use HTMLCanvas + drawWindow via html2canvas for exact screenshot but keep page open
    const addScript = (src) => new Promise(r => { const s = document.createElement('script'); s.src = src; s.onload = r; document.head.appendChild(s); });
    (async () => {
      // Load html2canvas from CDN once
      if (!window.html2canvas) {
        await addScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
      }
      const node = document.querySelector('.invoice-container');
      const hideEls = node.querySelectorAll('.no-print');
      hideEls.forEach(el => el.style.visibility = 'hidden');
      const canvas = await window.html2canvas(node, {scale: 2, useCORS: true});
      hideEls.forEach(el => el.style.visibility = '');
      const link = document.createElement('a');
      link.href = canvas.toDataURL('image/png');
      const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
      const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
      const customerName = firstName + (lastName ? ' ' + lastName : '');
      const phoneNumber = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->phone_original ?? "Unknown") }}';
      const orderNumber = '{{ $order->order_number }}';
      link.download = customerName + ' ' + phoneNumber + ' ' + orderNumber + '.png';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // Keep the page open so user can view the invoice
    })();
  }
})();

</script>

@if ($__paidStampData['show'] && $__canEditStamp)
{{-- =============================================================
    PAID-STAMP EDITOR MODAL
    Only loaded when the stamp is visible AND user is logged in.
    Saves display-only overrides to t_crm_prod_order.paid_stamp_*
    via POST /orders/{id}/paid-stamp. No payment row is touched.
============================================================= --}}
<div id="paidStampEditorBackdrop" class="no-print"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9998;"
     onclick="closePaidStampEditor(event)"></div>

<div id="paidStampEditorModal" class="no-print"
     style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            background:#fff; border-radius:12px; width:440px; max-width:92vw;
            box-shadow:0 20px 50px rgba(0,0,0,.3); z-index:9999;
            font-family:Arial,Helvetica,sans-serif;">
    <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
        <div style="font-size:15px; font-weight:700; color:#7F1D1D;">✏️ Edit Invoice PAID Stamp</div>
        <button type="button" onclick="closePaidStampEditor()"
                style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">×</button>
    </div>
    <div style="padding:16px 20px;">
        <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:6px; padding:8px 10px; margin-bottom:12px; font-size:11px; color:#78350f;">
            These changes only affect how the stamp is displayed on this invoice.
            Actual payment records are not modified.
        </div>

        <div style="margin-bottom:12px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px;">
                Customer's Sending Bank
            </label>
            <input type="text" id="stampEditSendingBank"
                   value="{{ $order->paid_stamp_sending_bank ?? '' }}"
                   placeholder="e.g. Meezan Bank, HBL, JazzCash... (leave blank for CASH)"
                   style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
            <div style="font-size:11px; color:#6b7280; margin-top:3px;">
                Shown as <em>via: ...</em> on the stamp. Leave blank for cash payments (stamp shows "via: CASH").
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px;">
                Stamp Date
            </label>
            <input type="date" id="stampEditDate"
                   value="{{ $order->paid_stamp_date?->format('Y-m-d') ?? '' }}"
                   style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
            <div style="font-size:11px; color:#6b7280; margin-top:3px;">
                Defaults to last payment date. Overriding here does not affect payment records.
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px;">
                Show on Stamp (third line)
            </label>
            <select id="stampEditRefMode"
                    style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff;">
                @php $__rm = $order->paid_stamp_ref_mode ?: 'reference'; @endphp
                <option value="reference"      {{ $__rm === 'reference' ? 'selected' : '' }}>Transaction reference (from payment)</option>
                <option value="customer_name"  {{ $__rm === 'customer_name' ? 'selected' : '' }}>Customer name</option>
                <option value="blank"          {{ $__rm === 'blank' ? 'selected' : '' }}>Blank / hide line</option>
            </select>
        </div>

        <div id="stampEditStatus" style="font-size:12px; margin-bottom:8px; min-height:16px;"></div>
    </div>
    <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" onclick="closePaidStampEditor()"
                style="padding:8px 14px; border:1px solid #d1d5db; background:#fff; border-radius:6px; font-size:13px; cursor:pointer;">
            Cancel
        </button>
        <button type="button" onclick="savePaidStamp()" id="stampEditSaveBtn"
                style="padding:8px 14px; border:none; background:#7F1D1D; color:#fff; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
            Save Stamp
        </button>
    </div>
</div>

<script>
function openPaidStampEditor(ev) {
    if (ev) ev.preventDefault();
    document.getElementById('paidStampEditorBackdrop').style.display = 'block';
    document.getElementById('paidStampEditorModal').style.display = 'block';
    document.getElementById('stampEditStatus').textContent = '';
}
function closePaidStampEditor(ev) {
    if (ev && ev.target && ev.target.id !== 'paidStampEditorBackdrop' && ev.currentTarget !== ev.target) return;
    document.getElementById('paidStampEditorBackdrop').style.display = 'none';
    document.getElementById('paidStampEditorModal').style.display = 'none';
}
async function savePaidStamp() {
    const btn = document.getElementById('stampEditSaveBtn');
    const status = document.getElementById('stampEditStatus');
    const payload = {
        sending_bank:   document.getElementById('stampEditSendingBank').value || '',
        stamp_date:     document.getElementById('stampEditDate').value || '',
        stamp_ref_mode: document.getElementById('stampEditRefMode').value || 'reference',
    };
    btn.disabled = true;
    const oldLabel = btn.textContent;
    btn.textContent = 'Saving...';
    status.style.color = '#6b7280';
    status.textContent = '';
    try {
        const res = await fetch('/orders/{{ $order->id }}/paid-stamp', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error((data && data.message) ? (typeof data.message === 'string' ? data.message : 'Validation error') : ('HTTP ' + res.status));
        }
        status.style.color = '#059669';
        status.textContent = '✓ Saved. Reloading invoice...';
        setTimeout(() => { window.location.reload(); }, 600);
    } catch (e) {
        status.style.color = '#b91c1c';
        status.textContent = '✗ ' + (e.message || 'Failed to save');
        btn.disabled = false;
        btn.textContent = oldLabel;
    }
}
</script>
@endif

</body>
</html>
